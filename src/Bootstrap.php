<?php

declare(strict_types=1);

namespace Station0;

use DI\Container;
use Delight\Auth\Auth;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;
use Slim\App;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\Loader\FilesystemLoader;
use Station0\Controller\Admin\AuthController;
use Station0\Controller\Admin\CollectionController;
use Station0\Controller\Admin\DashboardController;
use Station0\Controller\Admin\PageController as AdminPageController;
use Station0\Controller\Admin\SettingsController;
use Station0\Controller\Admin\SetupController;
use Station0\Controller\Admin\TaskController;
use Station0\Controller\Admin\UploadController;
use Station0\Controller\Admin\UserController;
use Station0\Controller\AssetController;
use Station0\Controller\PageController;
use Station0\Middleware\AuthMiddleware;
use Station0\Middleware\RoleMiddleware;
use Station0\Service\BlockRegistry;
use Station0\Service\CollectionGroups;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\FieldOptions;
use Station0\Service\FileCache;
use Station0\Service\MediaService;
use Station0\Service\MailerService;
use Station0\Service\PageFields;
use Station0\Service\PageRenderer;
use Station0\Service\TaskHooks;
use Station0\Service\TaskLauncher;
use Station0\Service\TaskRegistry;
use Station0\Service\TemplateBlocks;
use Station0\Service\ThumbService;
use Station0\Service\UserRepository;

final class Bootstrap
{
    public static function createApp(): App
    {
        $station0Root = dirname(__DIR__);
        $projectRoot  = self::findProjectRoot($station0Root);
        $siteRoot     = rtrim(getenv('SITE_PATH') ?: ($projectRoot . '/site'), '/');
        $configFactory = require $siteRoot . '/config.php';
        $config = $configFactory($station0Root, $siteRoot, $projectRoot);
        $roles = require $station0Root . '/config/roles.php';

        foreach ([$config['paths']['cache'], $config['paths']['sessions'], $config['paths']['logs'], $config['paths']['uploads']] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        self::startSession($config);

        $container = self::buildContainer($config, $roles, $station0Root);

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->addRoutingMiddleware();
        $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
        $app->add(new \Station0\Middleware\CsrfTwigGlobalMiddleware(
            $container->get(Guard::class),
            $container->get(Twig::class),
        ));
        $app->add($container->get(Guard::class));

        $app->addErrorMiddleware($config['debug'], true, true, $container->get(Logger::class));

        self::registerRoutes($app);

        return $app;
    }

    private static function startSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_save_path($config['paths']['sessions']);
        session_name($config['session']['name']);
        session_set_cookie_params([
            'lifetime' => $config['session']['cookieLifetime'],
            'path' => '/',
            'secure' => $config['session']['secure'],
            'httponly' => true,
            'samesite' => $config['session']['sameSite'],
        ]);
        session_start();
    }

    private static function buildContainer(array $config, array $roles, string $station0Root): Container
    {
        $container = new Container();

        $container->set('config', $config);
        $container->set('roles', $roles);
        $container->set('station0Root', $station0Root);

        // Admin UI translations — set 'admin_locale' in site/config.php ('en' default).
        // Missing keys always fall back to the English base. Shared by Twig (as the
        // `t` global) and by controllers that emit user-facing messages.
        $container->set('lang', function () use ($config, $station0Root) {
            $locale   = preg_replace('/[^a-z]/', '', strtolower($config['admin_locale'] ?? 'en'));
            $langFile = $station0Root . '/admin/lang/' . $locale . '.php';
            $base     = require $station0Root . '/admin/lang/en.php';
            return is_file($langFile) && $langFile !== $station0Root . '/admin/lang/en.php'
                ? array_merge($base, require $langFile)
                : $base;
        });

        $container->set(Logger::class, function () use ($config) {
            $logger = new Logger('station0');
            $logger->pushHandler(new StreamHandler($config['paths']['logs'] . '/app.log', Logger::DEBUG));
            return $logger;
        });

        $container->set(PDO::class, function () use ($config) {
            $dbPath = $config['paths']['db'];
            // An empty file (left by an earlier failed run) still needs the schema.
            $fresh = !file_exists($dbPath) || filesize($dbPath) === 0;
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($fresh) {
                $schema = file_get_contents($config['paths']['projectRoot'] . '/vendor/delight-im/auth/Database/SQLite.sql');
                $pdo->exec($schema);
            }
            return $pdo;
        });

        $container->set(Auth::class, function ($c) {
            return new Auth($c->get(PDO::class), $_SERVER['REMOTE_ADDR'] ?? null);
        });

        $container->set(Twig::class, function ($c) use ($config, $station0Root) {
            $twig = Twig::create([
                FilesystemLoader::MAIN_NAMESPACE => $config['paths']['templates'],
                'admin'                          => $config['paths']['adminTemplates'],
            ], [
                'cache'       => $config['debug'] ? false : $config['paths']['cache'] . '/twig',
                'auto_reload' => true,
            ]);
            $twig->getEnvironment()->addGlobal('app', [
                'name'          => $config['name'],
                'baseUrl'       => $config['baseUrl'],
                'adminPath'     => $config['adminPath'],
                'blockCollapse' => $config['admin']['blockCollapse'] ?? 'remember',
            ]);

            $twig->getEnvironment()->addGlobal('t', $c->get('lang'));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'top_level_pages',
                function () use ($c) {
                    $repo = $c->get(ContentRepository::class);
                    return array_values(array_filter(
                        $repo->all(false),
                        fn (\Station0\Service\Page $p) => $p->depth() === 1
                    ));
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'page_fields',
                // Typed, asset-resolved template fields of any page (e.g. a child
                // in a listing); the current page's are also passed as `fields`.
                fn (\Station0\Service\Page $page) => $c->get(PageFields::class)->resolved($page)
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'page',
                // A published page by URL path, e.g. the value of an
                // `options_from: pages` select; null when missing or not live.
                function (?string $urlPath) use ($c) {
                    if ($urlPath === null || trim($urlPath) === '') {
                        return null;
                    }
                    $page = $c->get(ContentRepository::class)->find('/' . trim($urlPath, '/'));
                    return $page !== null && $page->isLive() ? $page : null;
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'child_pages',
                function (string $parentUrl) use ($c) {
                    $repo      = $c->get(ContentRepository::class);
                    $parentUrl = '/' . trim($parentUrl, '/');
                    return array_values(array_filter(
                        $repo->all(false),
                        function (\Station0\Service\Page $p) use ($parentUrl) {
                            if ($p->urlPath === '/') {
                                return false;
                            }
                            $pp = rtrim(dirname($p->urlPath), '/') ?: '/';
                            return $pp === $parentUrl;
                        }
                    ));
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'has_streams',
                function () use ($c) {
                    foreach ($c->get(ContentRepository::class)->all(false) as $page) {
                        if (!empty($page->allowedChildTemplates)) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'has_collections',
                function () use ($c) {
                    // True only when some collection is left for the generic tab;
                    // grouped collections live under their own group tabs.
                    $groups = $c->get(CollectionGroups::class);
                    foreach ($c->get(CollectionRepository::class)->names() as $name) {
                        if ($groups->groupOf($name) === null) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'collection_groups',
                function () use ($c, $config) {
                    // Menu tabs the current user may see. A single-collection
                    // group links straight to that collection's items.
                    return array_map(function (array $g) use ($config) {
                        $g['url'] = $config['adminPath'] . (count($g['collections']) === 1
                            ? '/collections/' . $g['collections'][0]['name']
                            : '/collection-groups/' . $g['id']);
                        return $g;
                    }, $c->get(CollectionGroups::class)->visible());
                }
            ));
            // ── Thumbnails ────────────────────────────────────────────────────
            // {{ image.src|thumb(600) }}, |thumb(400, 400, 'cover'), |thumb(600, format='webp'),
            // srcset="{{ image.src|thumb_srcset([400, 800, 1200]) }}"
            $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
                'thumb',
                fn (?string $src, int $width, int $height = 0, string $fit = 'contain', ?string $format = null)
                    => $c->get(ThumbService::class)->url($src, $width, $height, $fit, $format)
            ));
            $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
                'thumb_srcset',
                fn (?string $src, array $widths, ?string $format = null)
                    => $c->get(ThumbService::class)->srcset($src, $widths, $format)
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'has_tasks',
                // Site tasks (site/tasks/*.php) the current user may run.
                fn () => $c->get(TaskRegistry::class)->visible() !== []
            ));
            // ── Collections Twig functions ────────────────────────────────────
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'collection',
                function (string $name) use ($c) {
                    return $c->get(CollectionRepository::class)->items($name);
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'collection_item',
                function (?string $name, ?string $slug = null) use ($c) {
                    // One argument: "<collection>/<slug>" (an `options_from: collections` value).
                    if ($slug === null) {
                        [$name, $slug] = array_pad(explode('/', trim((string) $name, '/'), 2), 2, '');
                    }
                    if ((string) $name === '' || (string) $slug === '') {
                        return null;
                    }
                    return $c->get(CollectionRepository::class)->find($name, $slug);
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'render_collection_item',
                function (\Station0\Service\CollectionItem $item) use ($c) {
                    $renderer = $c->get(PageRenderer::class);
                    $virtualPath = CollectionRepository::virtualPath($item->collection, $item->slug);
                    // Wrap item into a temporary Page-like structure for PageRenderer.
                    $page = new \Station0\Service\Page(
                        slug:      $item->slug,
                        title:     $item->title,
                        body:      $item->body,
                        published: $item->published,
                    );
                    $page->urlPath  = $virtualPath;
                    $page->filePath = $item->filePath;
                    $mtime = $item->filePath && is_file($item->filePath)
                        ? (int) filemtime($item->filePath)
                        : 0;
                    return $renderer->render($page, $mtime);
                },
                ['is_safe' => ['html']]
            ));
            return $twig;
        });

        $container->set(Guard::class, function () {
            $guard = new Guard(new ResponseFactory());
            // Keep the same token valid across the session — async POSTs (uploads, etc.)
            // reuse the token rendered into the page; per-request rotation breaks them.
            $guard->setPersistentTokenMode(true);
            return $guard;
        });

        $container->set(FileCache::class, fn () => new FileCache($config['paths']['cache']));

        $container->set(MarkdownConverter::class, function () {
            // Escape raw HTML and drop unsafe (javascript:, data:) links so a
            // semi-trusted editor cannot inject script into rendered page content.
            $env = new Environment([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new GithubFlavoredMarkdownExtension());
            $env->addExtension(new FrontMatterExtension());
            return new MarkdownConverter($env);
        });

        $container->set(ContentRepository::class, fn ($c) => new ContentRepository(
            $config['paths']['content'] . '/pages'
        ));

        $container->set(BlockRegistry::class, fn ($c) => new BlockRegistry(
            $config['paths']['templates'] . '/blocks',
            $c->get(Twig::class),
        ));

        $container->set(TemplateBlocks::class, fn ($c) => new TemplateBlocks(
            $config['paths']['templates'],
            $c->get(BlockRegistry::class),
        ));

        $container->set(PageFields::class, fn ($c) => new PageFields(
            $c->get(TemplateBlocks::class),
            $c->get(MediaService::class),
        ));

        $container->set(PageRenderer::class, fn ($c) => new PageRenderer(
            $c->get(MarkdownConverter::class),
            $c->get(BlockRegistry::class),
            $c->get(FileCache::class),
            $c->get(MediaService::class),
            $c->get(ThumbService::class),
            (int) ($config['thumbs']['markdown'] ?? PageRenderer::MARKDOWN_THUMB_WIDTH),
        ));

        $container->set(UserRepository::class, fn ($c) => new UserRepository(
            $c->get(Auth::class),
            $c->get(PDO::class),
            $roles
        ));

        $container->set(MailerService::class, fn () => new MailerService($config['mail']));

        $container->set(AuthController::class, fn ($c) => new AuthController(
            $c->get(Auth::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $c->get(MailerService::class),
            $config['baseUrl'],
            $config['adminPath'],
            $c->get('lang'),
        ));

        $container->set(AuthMiddleware::class, fn ($c) => new AuthMiddleware(
            $c->get(Auth::class),
            $c->get(UserRepository::class),
            $config['adminPath'],
            $c->get(Twig::class),
            $roles,
        ));

        $container->set(SetupController::class, fn ($c) => new SetupController(
            $c->get(Auth::class),
            $c->get(UserRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $config['adminPath'],
            $c->get('lang'),
            $c->get(Logger::class),
        ));
        $container->set('middleware.role', fn ($c) => fn (string $name) => new RoleMiddleware($c->get(Auth::class), $roles, $name));

        $container->set(DashboardController::class, fn ($c) => new DashboardController(
            $c->get(Auth::class),
            $c->get(Twig::class),
            $c->get(ContentRepository::class),
            $roles
        ));

        $container->set(AdminPageController::class, fn ($c) => new AdminPageController(
            $c->get(ContentRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $c->get(FileCache::class),
            $c->get(BlockRegistry::class),
            $c->get(PageRenderer::class),
            $c->get(TemplateBlocks::class),
            $c->get(PageFields::class),
            $config['adminPath'],
            $config['paths']['templates'],
            $c->get(FieldOptions::class),
            $c->get(TaskHooks::class),
        ));

        $container->set(UserController::class, fn ($c) => new UserController(
            $c->get(UserRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $roles,
            $config['adminPath'],
            $c->get(Auth::class),
            $c->get('lang'),
        ));

        $container->set(CollectionRepository::class, fn () => new CollectionRepository(
            $config['paths']['content'] . '/collections'
        ));

        $container->set(CollectionGroups::class, fn ($c) => new CollectionGroups(
            $c->get(CollectionRepository::class),
            function (string $role) use ($c, $roles): bool {
                $auth = $c->get(Auth::class);
                return isset($roles[$role]) && $auth->isLoggedIn() && $auth->hasRole($roles[$role]);
            },
        ));

        $container->set(FieldOptions::class, fn ($c) => new FieldOptions(
            $c->get(CollectionRepository::class),
            $c->get(ContentRepository::class),
        ));

        $container->set(MediaService::class, fn ($c) => new MediaService(
            $c->get(ContentRepository::class),
            $config['paths']['content'] . '/pages',
            $config['paths']['content'] . '/collections',
        ));

        $container->set(UploadController::class, fn ($c) => new UploadController(
            $c->get(MediaService::class),
            $c->get(ThumbService::class),
        ));

        $container->set(CollectionController::class, fn ($c) => new CollectionController(
            $c->get(CollectionRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $c->get(FileCache::class),
            $c->get(MediaService::class),
            $config['adminPath'],
            $c->get(FieldOptions::class),
            $c->get(CollectionGroups::class),
            $c->get(ThumbService::class),
            $c->get(TaskHooks::class),
        ));

        $container->set(ThumbService::class, fn ($c) => self::thumbService($config, $c->get(MediaService::class)));

        $container->set(AssetController::class, fn ($c) => new AssetController(
            $c->get(MediaService::class),
            $c->get(ThumbService::class),
        ));

        $container->set(TaskRegistry::class, fn ($c) => new TaskRegistry(
            $config['paths']['tasks'] ?? dirname($config['paths']['templates']) . '/tasks',
            $config['paths']['logs'] . '/tasks',
            $config,
            $c->get(ContentRepository::class),
            $c->get(CollectionRepository::class),
            $c->get(FileCache::class),
            $c->get(FieldOptions::class),
            function (string $role) use ($c, $roles): bool {
                $auth = $c->get(Auth::class);
                return isset($roles[$role]) && $auth->isLoggedIn() && $auth->hasRole($roles[$role]);
            },
        ));

        $container->set(TaskLauncher::class, fn ($c) => new TaskLauncher(
            $c->get(TaskRegistry::class),
            $station0Root,
            $config['paths']['projectRoot'],
            (array) ($config['tasks'] ?? []),
        ));

        $container->set(TaskHooks::class, fn ($c) => new TaskHooks(
            $c->get(TaskRegistry::class),
            $c->get(TaskLauncher::class),
            function () use ($c): ?string {
                $auth = $c->get(Auth::class);
                return $auth->isLoggedIn() ? $auth->getEmail() : null;
            },
            $c->get(Logger::class),
        ));

        $container->set(TaskController::class, fn ($c) => new TaskController(
            $c->get(TaskRegistry::class),
            $c->get(TaskLauncher::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $c->get(Auth::class),
            $config['adminPath'],
            $config['paths']['cache'] . '/task-uploads',
            $c->get('lang'),
        ));

        $container->set(SettingsController::class, fn ($c) => new SettingsController(
            $c->get(Auth::class),
            $c->get(Twig::class),
            $roles,
            $config['paths']['projectRoot'],
            $config['paths']['templates'],
            $station0Root,
        ));

        return $container;
    }

    private static function registerRoutes(App $app): void
    {
        $container = $app->getContainer();
        $roleMiddleware = fn (string $name) => $container->get('middleware.role')($name);
        $adminPath = $container->get('config')['adminPath'];

        $app->get('/', [PageController::class, 'home'])->setName('home');

        $app->group($adminPath, function ($group) use ($roleMiddleware) {
            $group->get('/setup', [SetupController::class, 'showSetup'])->setName('admin.setup');
            $group->post('/setup', [SetupController::class, 'setup']);
            $group->get('/login', [AuthController::class, 'showLogin'])->setName('admin.login');
            $group->post('/login', [AuthController::class, 'login']);
            $group->get('/forgot-password', [AuthController::class, 'showForgotPassword'])->setName('admin.forgot-password');
            $group->post('/forgot-password', [AuthController::class, 'forgotPassword']);
            $group->get('/reset-password', [AuthController::class, 'showResetPassword'])->setName('admin.reset-password');
            $group->post('/reset-password', [AuthController::class, 'resetPassword']);

            $group->group('', function ($authed) use ($roleMiddleware) {
                $authed->post('/logout', [AuthController::class, 'logout'])->setName('admin.logout');
                $authed->get('', [DashboardController::class, 'index'])->setName('admin.dashboard');
                $authed->get('/', [DashboardController::class, 'index']);

                $authed->get('/pages', [AdminPageController::class, 'index'])->setName('admin.pages.index');
                $authed->get('/pages/new', [AdminPageController::class, 'createForm'])->setName('admin.pages.new');
                $authed->get('/pages/templates', [AdminPageController::class, 'templatesForParent'])->setName('admin.pages.templates');
                $authed->post('/pages/create', [AdminPageController::class, 'store'])->setName('admin.pages.store');
                $authed->post('/pages/reorder', [AdminPageController::class, 'reorder'])->setName('admin.pages.reorder');
                $authed->get('/pages/{path:.+}/edit', [AdminPageController::class, 'editForm'])->setName('admin.pages.edit');
                $authed->post('/pages/{path:.+}/update', [AdminPageController::class, 'update'])->setName('admin.pages.update');
                $authed->post('/pages/{path:.+}/delete', [AdminPageController::class, 'delete'])->setName('admin.pages.delete');

                $authed->post('/upload', [UploadController::class, 'store'])->setName('admin.upload');
                $authed->post('/upload-collection', [CollectionController::class, 'upload'])->setName('admin.upload-collection');

                $authed->get('/collections', [CollectionController::class, 'index'])->setName('admin.collections.index');
                $authed->get('/collection-groups/{group}', [CollectionController::class, 'group'])->setName('admin.collections.group');
                $authed->get('/collections/{name}', [CollectionController::class, 'items'])->setName('admin.collections.items');
                $authed->get('/collections/{name}/new', [CollectionController::class, 'createForm'])->setName('admin.collections.new');
                $authed->post('/collections/{name}/create', [CollectionController::class, 'store'])->setName('admin.collections.store');
                $authed->get('/collections/{name}/{slug}/edit', [CollectionController::class, 'editForm'])->setName('admin.collections.edit');
                $authed->post('/collections/{name}/{slug}/update', [CollectionController::class, 'update'])->setName('admin.collections.update');
                $authed->post('/collections/{name}/{slug}/delete', [CollectionController::class, 'delete'])->setName('admin.collections.delete');

                $authed->get('/tasks', [TaskController::class, 'index'])->setName('admin.tasks.index');
                $authed->get('/tasks/{name}', [TaskController::class, 'show'])->setName('admin.tasks.show');
                $authed->post('/tasks/{name}/run', [TaskController::class, 'run'])->setName('admin.tasks.run');
                $authed->get('/tasks/{name}/runs/{id}', [TaskController::class, 'runStatus'])->setName('admin.tasks.run-status');

                $authed->group('/users', function ($admin) {
                    $admin->get('', [UserController::class, 'index'])->setName('admin.users.index');
                    $admin->get('/new', [UserController::class, 'createForm'])->setName('admin.users.new');
                    $admin->post('', [UserController::class, 'store'])->setName('admin.users.store');
                    $admin->post('/{id}/delete', [UserController::class, 'delete'])->setName('admin.users.delete');
                })->add($roleMiddleware('admin'));

                $authed->get('/settings', [SettingsController::class, 'index'])
                    ->setName('admin.settings')
                    ->add($roleMiddleware('admin'));
            })->add(AuthMiddleware::class);
        });

        // Admin static assets (CSS, JS) served from the library package — no auth required.
        $app->get($adminPath . '/assets/{file:[a-z0-9._-]+}', function ($request, $response, $args) use ($container) {
            $filePath = $container->get('station0Root') . '/admin/assets/' . $args['file'];
            if (!is_file($filePath)) {
                return $response->withStatus(404);
            }
            $mime = match(pathinfo($filePath, PATHINFO_EXTENSION)) {
                'css'  => 'text/css',
                'js'   => 'application/javascript',
                default => 'application/octet-stream',
            };
            $response->getBody()->write((string) file_get_contents($filePath));
            return $response
                ->withHeader('Content-Type', $mime . '; charset=utf-8')
                ->withHeader('Cache-Control', 'public, max-age=86400');
        })->setName('admin.assets');

        // Page-local assets — must precede the page catch-all below.
        $app->get('/media/{path:.+}', [AssetController::class, 'show'])->setName('media.show');
        $app->get('/thumb/{spec}/{sig}/{path:.+}', [AssetController::class, 'thumb'])->setName('media.thumb');

        // Catch-all for public pages — multi-segment paths like /about/team (registered last)
        $app->get('/{slug:.+}', [PageController::class, 'show'])->setName('page.show');
    }

    /**
     * Thumbnail service from the optional `thumbs` config section:
     *   secret   — signing key (default: generated once into writable/thumbs.key,
     *              outside cache/ so clearing the cache keeps rendered URLs valid)
     *   static   — true: write thumbnails under public/thumb/ for the web server
     *   format   — 'webp': convert thumbnails to WebP by default
     *   markdown — max width of markdown images in text blocks (0 = off)
     * Shared with bin/console.
     */
    public static function thumbService(array $config, MediaService $media): ThumbService
    {
        $thumbs = $config['thumbs'] ?? [];
        $public = self::publicDir($config);
        return new ThumbService(
            $media,
            $config['paths']['cache'],
            (string) ($thumbs['secret']
                ?? ThumbService::loadOrCreateKey(dirname($config['paths']['cache']) . '/thumbs.key')),
            !empty($thumbs['static']) ? $public : null,
            isset($thumbs['format']) ? (string) $thumbs['format'] : null,
        );
    }

    /** Web root used for static thumbnails (also by `thumbs:clear`). */
    public static function publicDir(array $config): string
    {
        return $config['paths']['public']
            ?? rtrim($config['paths']['projectRoot'] ?? dirname($config['paths']['cache'], 2), '/') . '/public';
    }

    private static function findProjectRoot(string $packageRoot): string
    {
        // Real vendor install: path ends with vendor/<vendor>/<package>
        if (basename(dirname(dirname($packageRoot))) === 'vendor') {
            return dirname($packageRoot, 3);
        }
        // Symlinked dev install or direct clone: walk up from CWD.
        // PHP's built-in server sets CWD to the served file's directory (e.g. public/),
        // so we may need to climb one or two levels to find the project root.
        $dir = (string) getcwd();
        while ($dir !== '' && $dir !== dirname($dir)) {
            if (is_dir($dir . '/vendor') && is_file($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        // Last resort: package root itself (e.g. running tests inside the library)
        return $packageRoot;
    }
}

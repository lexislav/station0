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
use Station0\Controller\MemberAuthController;
use Station0\Controller\PageController;
use Station0\Middleware\AccessMiddleware;
use Station0\Middleware\AuthMiddleware;
use Station0\Middleware\RoleMiddleware;
use Station0\Middleware\VisitorMiddleware;
use Station0\Service\AccessPolicy;
use Station0\Service\ActionContext;
use Station0\Service\ActionRegistry;
use Station0\Service\ActionState;
use Station0\Service\BlockRegistry;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\FieldOptions;
use Station0\Service\FileCache;
use Station0\Service\LoginLinks;
use Station0\Service\MediaService;
use Station0\Service\Members;
use Station0\Service\PassStore;
use Station0\Service\RateLimiter;
use Station0\Service\NavGroups;
use Station0\Service\MailerService;
use Station0\Service\PageFields;
use Station0\Service\PageRenderer;
use Station0\Service\TaskHooks;
use Station0\Service\TaskLauncher;
use Station0\Service\TaskRegistry;
use Station0\Service\TemplateBlocks;
use Station0\Service\ThumbService;
use Station0\Service\UserRepository;
use Station0\Service\VisibilityHorizon;
use Station0\Service\Visitor;

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
        self::applyTimezone($config);

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
        $app->add(VisitorMiddleware::class);
        $app->add($container->get(Guard::class));

        $app->addErrorMiddleware($config['debug'], true, true, $container->get(Logger::class));

        if (($config['access']['mode'] ?? 'public') === 'members' && ($config['access']['media'] ?? true) !== false
            && !empty($config['thumbs']['static'])) {
            $container->get(Logger::class)->warning(
                'access: media are members-only, but thumbs.static writes thumbnails to public/thumb/, '
                . 'where the web server serves them to anyone. Turn thumbs.static off.'
            );
        }

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
            // Members-only access (see AccessPolicy) — paths for sign-in forms.
            $loginPath = $c->get(AccessPolicy::class)->loginPath();
            $twig->getEnvironment()->addGlobal('access', [
                'mode'         => $c->get(AccessPolicy::class)->mode(),
                'loginPath'    => $loginPath,
                'linkPath'     => $loginPath !== null ? $loginPath . '/link' : null,
                'passwordPath' => $loginPath !== null ? $loginPath . '/password' : null,
                'logoutPath'   => $loginPath !== null ? $loginPath . '/logout' : null,
            ]);
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'action_state',
                // State of a site action (site/actions/), see ActionState; flashed
                // values are consumed by this read.
                function (string $namespace): array {
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        return [];
                    }
                    return (new ActionState($_SESSION, $namespace))->pull();
                }
            ));
            // Members-only pages (AccessPolicy) are left out of listings for
            // anonymous visitors; `includeGated: true` keeps them (teasers).
            $visible = fn (array $pages, bool $includeGated = false): array => $includeGated
                ? $pages
                : $c->get(AccessPolicy::class)->visiblePages($pages, $c->get(Visitor::class)->isAuthenticated());
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'top_level_pages',
                // Main menu: live top-level pages with `Listing: listed`.
                fn (bool $includeGated = false) => $visible($c->get(ContentRepository::class)->navChildren('/'), $includeGated)
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'nav_pages',
                // Menu / submenu items under a path: live, `Listing: listed`.
                fn (string $parentUrl = '/', bool $includeGated = false)
                    => $visible($c->get(ContentRepository::class)->navChildren($parentUrl), $includeGated)
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
                // Members-only pages are null for anonymous visitors unless includeGated.
                function (?string $urlPath, bool $includeGated = false) use ($c, $visible) {
                    if ($urlPath === null || trim($urlPath) === '') {
                        return null;
                    }
                    $page = $c->get(ContentRepository::class)->find('/' . trim($urlPath, '/'));
                    if ($page === null || !$page->isLive()) {
                        return null;
                    }
                    return $visible([$page], $includeGated) === [] ? null : $page;
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'child_pages',
                // Content listing: live children incl. nav-hidden; unlisted only on request.
                fn (string $parentUrl, bool $includeUnlisted = false, bool $includeGated = false)
                    => $visible($c->get(ContentRepository::class)->children($parentUrl, $includeUnlisted), $includeGated)
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'has_streams',
                function () use ($c) {
                    // Grouped streams live under their group's tab.
                    $groups = $c->get(NavGroups::class);
                    foreach ($c->get(ContentRepository::class)->all(false) as $page) {
                        if (!empty($page->allowedChildTemplates) && $groups->groupOfPage($page->urlPath) === null) {
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
                    $groups = $c->get(NavGroups::class);
                    foreach ($c->get(CollectionRepository::class)->names() as $name) {
                        if ($groups->groupOfCollection($name) === null) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'nav_groups',
                function () use ($c, $config) {
                    // Menu tabs the current user may see (collections + page subtrees).
                    $groups = $c->get(NavGroups::class);
                    return array_map(function (array $g) use ($config, $groups) {
                        $g['url'] = $config['adminPath'] . $groups->path($g);
                        return $g;
                    }, $groups->visible());
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
                    // Live items only, like collection() (drafts / scheduled / expired → null).
                    $item = $c->get(CollectionRepository::class)->find($name, $slug);
                    return $item !== null && $item->isLive() ? $item : null;
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
                        status:    $item->status,
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
            $c->get(VisibilityHorizon::class),
            fn (): string => $c->get(Visitor::class)->isAuthenticated() ? 'signed-in' : 'anonymous',
        ));

        $container->set(VisibilityHorizon::class, fn ($c) => new VisibilityHorizon(
            $c->get(FileCache::class),
            $c->get(ContentRepository::class),
            $c->get(CollectionRepository::class),
        ));

        $container->set(UserRepository::class, fn ($c) => new UserRepository(
            $c->get(Auth::class),
            $c->get(PDO::class),
            $roles
        ));

        $container->set(MailerService::class, fn () => new MailerService($config['mail']));

        // ── Members-only access ───────────────────────────────────────────────
        $container->set(AccessPolicy::class, fn ($c) => new AccessPolicy(
            (array) ($config['access'] ?? []),
            fn (string $urlPath) => $c->get(ContentRepository::class)->find($urlPath),
        ));
        $container->set(PassStore::class, fn ($c) => new PassStore($c->get(PDO::class)));
        $container->set(RateLimiter::class, fn ($c) => new RateLimiter($c->get(PDO::class)));
        $container->set(LoginLinks::class, fn ($c) => new LoginLinks($c->get(PDO::class)));
        $container->set(Members::class, fn ($c) => new Members($c->get(PDO::class), $c->get(Auth::class), $roles));
        $adminRoles = array_values(array_filter(
            (array) ($config['admin']['roles'] ?? ['admin', 'editor']),
            fn ($r) => isset($roles[$r]),
        ));
        $container->set(Visitor::class, fn ($c) => new Visitor(
            $c->get(PassStore::class),
            $c->get(Members::class),
            [
                'secure'       => (bool) $config['session']['secure'],
                'sameSite'     => (string) ($config['session']['sameSite'] ?? 'Lax'),
                'rememberDays' => (int) ($config['access']['rememberDays'] ?? 365),
                'sessionHours' => (int) ($config['access']['sessionHours'] ?? 12),
            ],
            // An admin/editor signed into the admin counts as a member on the site.
            // Auth is only built when there is an admin session or remember cookie.
            function () use ($c, $roles, $adminRoles): ?array {
                $hasSession = !empty($_SESSION[\Delight\Auth\UserManager::SESSION_FIELD_LOGGED_IN]);
                $hasCookie  = isset($_COOKIE[Auth::createRememberCookieName()]);
                if (!$hasSession && !$hasCookie) {
                    return null;
                }
                $auth = $c->get(Auth::class);
                if (!$auth->isLoggedIn()) {
                    return null;
                }
                foreach ($adminRoles as $name) {
                    if ($auth->hasRole($roles[$name])) {
                        $email = (string) $auth->getEmail();
                        return [
                            'userId' => (int) $auth->getUserId(),
                            'email'  => $email,
                            'label'  => (string) ($auth->getUsername() ?: strstr($email, '@', true)),
                        ];
                    }
                }
                return null;
            },
            null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ));
        $container->set(VisitorMiddleware::class, fn ($c) => new VisitorMiddleware(
            $c->get(Visitor::class),
            $c->get(Twig::class),
        ));
        $container->set(MemberAuthController::class, fn ($c) => new MemberAuthController(
            $c->get(AccessPolicy::class),
            $c->get(Visitor::class),
            $c->get(Members::class),
            $c->get(LoginLinks::class),
            $c->get(RateLimiter::class),
            $c->get(MailerService::class),
            $c->get(Twig::class),
            $c->get(Logger::class),
            $c->get('lang'),
            $config['baseUrl'],
            $config['name'],
            $config['paths']['templates'],
            $_SESSION,
        ));
        $container->set(ActionRegistry::class, fn ($c) => new ActionRegistry(
            $config['paths']['actions'] ?? dirname($config['paths']['templates']) . '/actions',
            array_values(array_filter([
                $config['adminPath'], '/media', '/thumb', $c->get(AccessPolicy::class)->loginPath(),
            ])),
        ));

        $container->set(AuthController::class, fn ($c) => new AuthController(
            $c->get(Auth::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $c->get(MailerService::class),
            $config['baseUrl'],
            $config['adminPath'],
            $c->get('lang'),
            array_map(fn (string $r) => $roles[$r], $adminRoles),
        ));

        $container->set(AuthMiddleware::class, fn ($c) => new AuthMiddleware(
            $c->get(Auth::class),
            $c->get(UserRepository::class),
            $config['adminPath'],
            $c->get(Twig::class),
            $roles,
            $adminRoles,
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

        // Public pages. Signed-in admins / editors may preview non-live pages;
        // Auth (and the DB) is only touched for those.
        $container->set(PageController::class, fn ($c) => new PageController(
            $c->get(ContentRepository::class),
            $c->get(PageRenderer::class),
            $c->get(Twig::class),
            $c->get(PageFields::class),
            // Only admin roles — not `member` accounts.
            function () use ($c, $roles, $adminRoles): bool {
                if ($adminRoles === []) {
                    return false;
                }
                $auth = $c->get(Auth::class);
                return $auth->isLoggedIn()
                    && $auth->hasAnyRole(...array_map(fn (string $r) => $roles[$r], $adminRoles));
            },
            $config['adminPath'],
            $c->get('lang'),
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
            $c->get(NavGroups::class),
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

        $container->set(NavGroups::class, fn ($c) => new NavGroups(
            $c->get(CollectionRepository::class),
            function (string $role) use ($c, $roles): bool {
                $auth = $c->get(Auth::class);
                return isset($roles[$role]) && $auth->isLoggedIn() && $auth->hasRole($roles[$role]);
            },
            $c->get(ContentRepository::class),
            $config['paths']['content'] . '/' . NavGroups::FILE,
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
            $c->get(NavGroups::class),
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

        $access     = $container->get(AccessPolicy::class);
        $visitor    = $container->get(Visitor::class);
        $pageAccess = new AccessMiddleware($access, $visitor, AccessMiddleware::PAGES);
        $mediaAccess = new AccessMiddleware($access, $visitor, AccessMiddleware::MEDIA);

        $app->get('/', [PageController::class, 'home'])->setName('home')->add($pageAccess);

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
                $authed->get('/groups/{group}', [AdminPageController::class, 'group'])->setName('admin.groups.show');
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
        $app->get('/media/{path:.+}', [AssetController::class, 'show'])->setName('media.show')->add($mediaAccess);
        $app->get('/thumb/{spec}/{sig}/{path:.+}', [AssetController::class, 'thumb'])->setName('media.thumb')->add($mediaAccess);

        // Member sign-in on the public site — only when `access.loginPath` is set.
        $loginPath = $access->loginPath();
        if ($loginPath !== null) {
            $app->get($loginPath, [MemberAuthController::class, 'show'])->setName('member.login');
            $app->post($loginPath, [MemberAuthController::class, 'login']);
            $app->post($loginPath . '/link', [MemberAuthController::class, 'requestLink'])->setName('member.link');
            $app->get($loginPath . '/link', [MemberAuthController::class, 'followLink']);
            $app->post($loginPath . '/password', [MemberAuthController::class, 'setPassword'])->setName('member.password');
            $app->post($loginPath . '/logout', [MemberAuthController::class, 'logout'])->setName('member.logout');
        }

        self::registerActions($app);

        // Catch-all for public pages — multi-segment paths like /about/team (registered last)
        $app->get('/{slug:.+}', [PageController::class, 'show'])->setName('page.show')->add($pageAccess);
    }

    /** Site actions (site/actions/*.php) — see ActionRegistry. */
    private static function registerActions(App $app): void
    {
        $container = $app->getContainer();
        $logger    = $container->get(Logger::class);

        foreach ($container->get(ActionRegistry::class)->all() as $action) {
            if ($action['error'] !== null) {
                $logger->error('Site action skipped: ' . $action['error']);
                continue;
            }
            $app->map($action['methods'], $action['path'], function ($request, $response) use ($action, $container) {
                $visitor = $container->get(Visitor::class);
                if ($action['access'] === AccessPolicy::MEMBERS && !$visitor->isAuthenticated()) {
                    $response->getBody()->write('Forbidden');
                    return $response->withStatus(403)->withHeader('Cache-Control', 'no-store');
                }
                $ctx = new ActionContext(
                    $action['name'],
                    $request,
                    $container->get(Twig::class),
                    $container->get('config'),
                    $visitor,
                    $container->get(Members::class),
                    $container->get(RateLimiter::class),
                    $container->get(ContentRepository::class),
                    $container->get(CollectionRepository::class),
                    $container->get(MailerService::class),
                    $container->get(Logger::class),
                    $_SESSION,
                );
                $result = ($action['handler'])($ctx);
                return $result instanceof \Psr\Http\Message\ResponseInterface ? $result : $ctx->back();
            })->setName('action.' . $action['name']);
        }
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

    /**
     * Site timezone from the optional `timezone` config key (e.g. 'Europe/Prague').
     * Naive front-matter datetimes (PublishedAt, PublishAt, ExpireAt) are read
     * in this zone. Missing or invalid = PHP's default. Shared with bin/console.
     */
    public static function applyTimezone(array $config): void
    {
        $tz = trim((string) ($config['timezone'] ?? ''));
        if ($tz === '') {
            return;
        }
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            return;
        }
        date_default_timezone_set($tz);
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

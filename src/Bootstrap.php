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
use Station0\Controller\Admin\DashboardController;
use Station0\Controller\Admin\PageController as AdminPageController;
use Station0\Controller\Admin\SetupController;
use Station0\Controller\Admin\UploadController;
use Station0\Controller\Admin\UserController;
use Station0\Controller\AssetController;
use Station0\Controller\PageController;
use Station0\Middleware\AuthMiddleware;
use Station0\Middleware\RoleMiddleware;
use Station0\Service\BlockRegistry;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\MediaService;
use Station0\Service\MailerService;
use Station0\Service\PageRenderer;
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

        $container = self::buildContainer($config, $roles);

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

    private static function buildContainer(array $config, array $roles): Container
    {
        $container = new Container();

        $container->set('config', $config);
        $container->set('roles', $roles);

        $container->set(Logger::class, function () use ($config) {
            $logger = new Logger('station0');
            $logger->pushHandler(new StreamHandler($config['paths']['logs'] . '/app.log', Logger::DEBUG));
            return $logger;
        });

        $container->set(PDO::class, function () use ($config) {
            $dbPath = $config['paths']['db'];
            $fresh = !file_exists($dbPath);
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

        $container->set(Twig::class, function ($c) use ($config) {
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
            $env = new Environment();
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

        $container->set(PageRenderer::class, fn ($c) => new PageRenderer(
            $c->get(MarkdownConverter::class),
            $c->get(BlockRegistry::class),
            $c->get(FileCache::class),
            $c->get(MediaService::class),
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
            $config['adminPath'],
            $config['paths']['templates'],
        ));

        $container->set(UserController::class, fn ($c) => new UserController(
            $c->get(UserRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $roles,
            $config['adminPath'],
        ));

        $container->set(MediaService::class, fn ($c) => new MediaService(
            $c->get(ContentRepository::class),
            $config['paths']['content'] . '/pages',
        ));

        $container->set(UploadController::class, fn ($c) => new UploadController(
            $c->get(MediaService::class),
        ));

        $container->set(AssetController::class, fn ($c) => new AssetController(
            $c->get(MediaService::class),
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

                $authed->group('/users', function ($admin) {
                    $admin->get('', [UserController::class, 'index'])->setName('admin.users.index');
                    $admin->get('/new', [UserController::class, 'createForm'])->setName('admin.users.new');
                    $admin->post('', [UserController::class, 'store'])->setName('admin.users.store');
                    $admin->post('/{id}/delete', [UserController::class, 'delete'])->setName('admin.users.delete');
                })->add($roleMiddleware('admin'));
            })->add(AuthMiddleware::class);
        });

        // Page-local assets — must precede the page catch-all below.
        $app->get('/media/{path:.+}', [AssetController::class, 'show'])->setName('media.show');

        // Catch-all for public pages — multi-segment paths like /about/team (registered last)
        $app->get('/{slug:.+}', [PageController::class, 'show'])->setName('page.show');
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

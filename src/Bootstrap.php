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
use Station0\Controller\PageController;
use Station0\Middleware\AuthMiddleware;
use Station0\Middleware\RoleMiddleware;
use Station0\Service\BlockRegistry;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
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

        $container->set(Twig::class, function () use ($config) {
            $twig = Twig::create([
                FilesystemLoader::MAIN_NAMESPACE => $config['paths']['templates'],
                'admin'                          => $config['paths']['adminTemplates'],
            ], [
                'cache'       => $config['debug'] ? false : $config['paths']['cache'] . '/twig',
                'auto_reload' => true,
            ]);
            $twig->getEnvironment()->addGlobal('app', [
                'name' => $config['name'],
                'baseUrl' => $config['baseUrl'],
                'adminPath' => $config['adminPath'],
            ]);
            return $twig;
        });

        $container->set(Guard::class, function () {
            return new Guard(new ResponseFactory());
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
        ));

        $container->set(UserController::class, fn ($c) => new UserController(
            $c->get(UserRepository::class),
            $c->get(Twig::class),
            $c->get(Guard::class),
            $roles,
            $config['adminPath'],
        ));

        $container->set(UploadController::class, fn ($c) => new UploadController(
            $config['paths']['uploads'],
            $config['uploadsUrl']
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
                $authed->post('/pages/create', [AdminPageController::class, 'store'])->setName('admin.pages.store');
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

        // Catch-all for public pages — multi-segment paths like /about/team (registered last)
        $app->get('/{slug:.+}', [PageController::class, 'show'])->setName('page.show');
    }

    private static function findProjectRoot(string $packageRoot): string
    {
        // Real vendor install: path ends with vendor/<vendor>/<package>
        if (basename(dirname(dirname($packageRoot))) === 'vendor') {
            return dirname($packageRoot, 3);
        }
        // Symlinked dev install or direct clone: use CWD (set by the server/CLI invocation)
        $cwd = (string) getcwd();
        if ($cwd !== '' && is_dir($cwd . '/vendor') && is_file($cwd . '/composer.json')) {
            return $cwd;
        }
        // Last resort: package root itself (e.g. running tests inside the library)
        return $packageRoot;
    }
}

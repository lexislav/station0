<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use Delight\Auth\Auth;
use Delight\Auth\Role;
use Delight\Auth\UserManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Station0\Bootstrap;

/**
 * The full app (Bootstrap::container() + app()) on a TestSite, with the
 * signed-in admin user faked in $_SESSION (Auth is final and reads the
 * sign-in from the session, so its constructor — session start, DB — is skipped).
 */
trait BootsApp
{
    private ?TestSite $site = null;
    private TestHandler $log;

    protected function tearDown(): void
    {
        $this->site?->remove();
        unset($_SESSION);
    }

    /** @param ?int $role Role::ADMIN / Role::EDITOR, null = signed out */
    private function boot(array $plugins, ?int $role = Role::ADMIN, ?string $pageTemplate = null, ?\Closure $prepare = null): App
    {
        $this->site?->remove();
        $this->site = new TestSite($plugins, $pageTemplate);
        if ($prepare !== null) {
            $prepare($this->site);
        }
        return $this->reboot($role);
    }

    /**
     * A fresh app on the current site — like the next PHP request. Needed
     * after a public page render: Twig globals cannot be added any more once
     * the environment rendered, and the admin adds `user`.
     */
    private function reboot(?int $role = Role::ADMIN): App
    {
        $config    = $this->site->config();
        $roles     = require dirname(__DIR__, 2) . '/config/roles.php';
        $this->log = new TestHandler();
        $logger    = new Logger('test', [$this->log]);

        $container = Bootstrap::container($config, $roles, dirname(__DIR__, 2), Bootstrap::plugins($config, $logger), $logger);
        $container->set(Auth::class, (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor());
        $this->signIn($role);

        return Bootstrap::app($container);
    }

    private function signIn(?int $role): void
    {
        $_SESSION = $role === null ? [] : [
            UserManager::SESSION_FIELD_LOGGED_IN => true,
            UserManager::SESSION_FIELD_USER_ID   => 1,
            UserManager::SESSION_FIELD_EMAIL     => 'ed@example.com',
            UserManager::SESSION_FIELD_ROLES     => $role,
        ];
    }

    private function get(App $app, string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $app->handle($request);
    }

    /**
     * POST with the CSRF fields taken from a GET of $formPage (the Guard keeps
     * its token in the container across requests).
     */
    private function post(App $app, string $path, array $data, string $formPage = '/admin', array $headers = []): ResponseInterface
    {
        $html = (string) $this->get($app, $formPage)->getBody();
        preg_match('/name="csrf_name" value="([^"]+)"/', $html, $name);
        preg_match('/name="csrf_value" value="([^"]+)"/', $html, $value);
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody($data + ['csrf_name' => $name[1] ?? '', 'csrf_value' => $value[1] ?? '']);
        foreach ($headers as $header => $v) {
            $request = $request->withHeader($header, $v);
        }
        return $app->handle($request);
    }
}

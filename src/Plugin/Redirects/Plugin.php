<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Monolog\Logger;
use Slim\Views\Twig;
use Station0\Plugin\BasePlugin;
use Station0\Plugin\Registrar;
use Station0\Service\AccessPolicy;
use Station0\Service\ActionRegistry;
use Station0\Service\ContentRepository;
use Station0\Service\Page;

/**
 * Redirect manager — bundled plugin `redirects` (docs/redirects.md).
 *
 *   'plugins' => ['redirects' => ['auto' => true, 'log404' => true]],
 *
 * Rules live in site/content/_redirects.yaml, compiled to
 * writable/cache/redirects.php. They are consulted only on the 404/410 path
 * (plus `force` rules, whose middleware exists only while there are any).
 */
final class Plugin extends BasePlugin
{
    public function name(): string
    {
        return 'redirects';
    }

    protected function defaults(): array
    {
        return [
            'auto'      => true,                                    // rule on slug change / move
            'log404'    => true,                                    // record misses
            'ignore404' => ['/wp-*', '*.php', '/.well-known/*', '/favicon.ico', '/apple-touch-icon*'],
            'maxAge'    => 3600,                                    // Cache-Control of 301 / 308
            'logLimit'  => 5000,                                    // rows kept in the 404 log
        ];
    }

    protected function configure(array $config): array
    {
        if (!is_array($config['ignore404'])) {
            throw new \InvalidArgumentException('ignore404 must be a list of glob patterns');
        }
        $config['auto']      = (bool) $config['auto'];
        $config['log404']    = (bool) $config['log404'];
        $config['ignore404'] = array_values(array_map('strval', $config['ignore404']));
        $config['maxAge']    = max(0, (int) $config['maxAge']);
        $config['logLimit']  = max(100, (int) $config['logLimit']);
        return $config;
    }

    public function register(Registrar $r): void
    {
        $options = $this->config();

        // ── Services ──────────────────────────────────────────────────────
        $r->service(Store::class, fn (ContainerInterface $c) => new Store(
            $c->get('config')['paths']['content'] . '/_redirects.yaml',
            $c->get('config')['paths']['cache'] . '/redirects.php',
            // Only needed when rules are validated (writes, recompiles).
            fn (): array => self::reservedPrefixes($c),
            $c->get(Logger::class),
        ));
        $r->service(Log::class, fn (ContainerInterface $c) => new Log(
            $c->get(\PDO::class),
            $options['ignore404'],
            $options['logLimit'],
        ));
        $r->service(Redirector::class, fn (ContainerInterface $c) => new Redirector(
            $c->get(Store::class),
            fn () => $c->get(Log::class),
            fn () => $c->get(Twig::class),
            $options['maxAge'],
            $options['log404'],
            $c->get(Logger::class),
        ));
        $r->service(ForceMiddleware::class, fn (ContainerInterface $c) => new ForceMiddleware($c->get(Redirector::class)));
        $r->service(AdminController::class, fn (ContainerInterface $c) => new AdminController(
            $c->get(Store::class),
            fn () => $c->get(Log::class),
            $c->get(Twig::class),
            $c->get(ContentRepository::class),
            $c->get('admin.user'),
            $c->get('config')['adminPath'],
        ));
        foreach (Log::migrations() as $id => $sql) {
            $r->migration($id, $sql);
        }

        // ── Public side ───────────────────────────────────────────────────
        $r->notFound(fn (ServerRequestInterface $request, ResponseInterface $response, string $path, int $status)
            => $r->get(Redirector::class)->notFound($request, $response, $path, $status));
        // Present only while some rule has `force: true` — then one isset()
        // (+ one preg_match for force patterns) per request.
        $r->middleware(ForceMiddleware::class, fn (): bool => $r->get(Store::class)->forceMatcher()->hasForce());

        // ── Auto rules ────────────────────────────────────────────────────
        if ($options['auto']) {
            $move = function (array $event, string $how) use ($r): void {
                if (!is_string($event['previous_path'] ?? null) || !is_string($event['path'] ?? null)) {
                    return;
                }
                $email = ($r->get('admin.user'))()['email'] ?? '';
                $r->get(Store::class)->recordMove($event['previous_path'], $event['path'], trim($how . ($email !== '' ? ' by ' . $email : '')));
            };
            $r->on('page.saved', fn (array $event) => $move($event, 'Renamed in admin'));
            $r->on('page.moved', fn (array $event) => $move($event, 'Moved in admin'));
        }

        // ── Admin ─────────────────────────────────────────────────────────
        $r->templates('redirects', __DIR__ . '/templates');
        $r->translations(['en' => require __DIR__ . '/lang/en.php', 'cs' => require __DIR__ . '/lang/cs.php']);
        $r->adminNav('redirects_nav', null, 'editor', '↪');
        $r->adminRoutes(function ($group) {
            $group->get('', [AdminController::class, 'index']);
            $group->post('/rules', [AdminController::class, 'save']);
            $group->post('/rules/{id}/delete', [AdminController::class, 'delete']);
            $group->get('/404', [AdminController::class, 'log']);
            $group->post('/404/ignore', [AdminController::class, 'ignore']);
            $group->post('/404/clear', [AdminController::class, 'clear']);
        }, 'editor');

        $r->contentPanel('page', 'redirects', 'redirects_panel', '@redirects/panel.twig',
            function (?Page $page, array $user) use ($r): array {
                if ($page === null) {
                    return ['new' => true];
                }
                $matcher = $r->get(Store::class)->matcher();
                return [
                    'new'      => false,
                    'path'     => $page->urlPath,
                    'incoming' => $matcher->incoming($page->urlPath),
                    'blocking' => $matcher->blocking($page->urlPath),
                    'shadowed' => $matcher->shadowed($page->urlPath),
                    'isAdmin'  => (bool) ($user['isAdmin'] ?? false),
                ];
            }, 'editor');
        $r->contentBadge('page', function (Page $page) use ($r): ?array {
            $matcher = $r->get(Store::class)->matcher();
            if ($matcher->blocking($page->urlPath) !== []) {
                return ['label' => '↪ ✕', 'title' => 'A force redirect makes this page unreachable', 'tone' => 'danger'];
            }
            $n = count($matcher->incoming($page->urlPath));
            return $n > 0 ? ['label' => '↪ ' . $n, 'title' => $n . ' redirect(s) lead here'] : null;
        }, 'editor');

        // ── Console ───────────────────────────────────────────────────────
        Console::register($r);
    }

    /** Sources no rule may take: the admin, thumbnails, member sign-in, site actions. */
    public static function reservedPrefixes(ContainerInterface $c): array
    {
        $reserved = [$c->get('config')['adminPath'], '/thumb'];
        try {
            $reserved[] = $c->get(AccessPolicy::class)->loginPath();
            foreach ($c->get(ActionRegistry::class)->all() as $action) {
                $reserved[] = $action['path'];
            }
        } catch (\Throwable) {
            // Missing optional services (e.g. in a bare console) — keep the fixed ones.
        }
        return array_values(array_filter($reserved, fn ($p) => is_string($p) && $p !== '' && $p !== '/'));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Station0\Plugin\BasePlugin;
use Station0\Plugin\Registrar;
use Station0\Service\CollectionItem;
use Station0\Service\Page;

/** Uses every Registrar method once (PluginAppTest, PluginConsoleTest). */
final class FixturePlugin extends BasePlugin
{
    public function name(): string
    {
        return 'fixture';
    }

    protected function defaults(): array
    {
        return ['middleware' => true, 'greeting' => 'hello'];
    }

    protected function configure(array $config): array
    {
        if (!is_string($config['greeting'])) {
            throw new \InvalidArgumentException('greeting must be a string');
        }
        return $config;
    }

    public function register(Registrar $r): void
    {
        $r->service('fixture.calls', fn () => new \ArrayObject());

        // Slim binds route closures to the container — capture, don't use $this.
        $greeting = $this->config('greeting');
        $r->publicRoutes(function ($app) use ($greeting) {
            $app->get('/fixture-hello', function ($request, $response) use ($greeting) {
                $response->getBody()->write($greeting . ' from fixture');
                return $response;
            });
        });

        $r->adminRoutes(function ($group) {
            $group->get('', function ($request, $response) {
                $response->getBody()->write('fixture admin');
                return $response;
            });
        });
        $r->adminRoutes(function ($group) {
            $group->get('/notes', function ($request, $response) {
                $response->getBody()->write('fixture notes');
                return $response;
            });
        }, 'editor');

        $r->middleware(
            function (ServerRequestInterface $request, $handler): ResponseInterface {
                return $handler->handle($request)->withHeader('X-Fixture', 'on');
            },
            fn (): bool => (bool) $this->config('middleware'),
        );

        $r->on('page.saved:/blog', function (array $payload) use ($r) {
            $r->get('fixture.calls')[] = $payload['event'] . ' ' . $payload['path'];
        });

        $r->notFound(function (ServerRequestInterface $request, ResponseInterface $response, string $path, int $status) {
            if ($path === '/old' || $path === '/media/old/a.jpg') {
                return $response->withStatus(301)->withHeader('Location', $path === '/old' ? '/new' : '/media/new/a.jpg');
            }
            return null;
        });

        $r->twigFunction('fixture_hello', fn (): string => 'hi from twig');
        $r->twigFilter('shout', fn (string $s): string => strtoupper($s));
        $r->twigGlobal('fixture', ['version' => 7]);
        $r->templates('fixture', __DIR__ . '/templates');

        $r->adminNav('fixture_nav_label', null, 'editor', '★');
        $r->translations([
            'en' => ['fixture_nav_label' => 'Fixture tab', 'fixture_panel' => 'Fixture panel'],
            'cs' => ['fixture_nav_label' => 'Záložka fixture'],
        ]);

        $r->contentPanel('page', 'info', 'fixture_panel', '@fixture/panel.twig',
            function (?Page $page, array $user): ?array {
                if ($page === null) {
                    return ['new' => true];
                }
                return $page->title === 'Quiet' ? null : ['path' => $page->urlPath, 'email' => $user['email'] ?? ''];
            });
        $r->contentPanel('page:/blog', 'blog-only', 'Blog panel', '@fixture/panel.twig',
            fn (?Page $page): array => ['path' => 'blog:' . $page?->urlPath]);
        $r->contentPanel('page', 'admins', 'Admins only', '@fixture/panel.twig',
            fn (?Page $page): array => ['path' => 'admins:' . $page?->urlPath], 'admin');
        $r->contentPanel('collectionItem:prod*', 'item', 'Item panel', '@fixture/panel.twig',
            fn (?CollectionItem $item): ?array => $item === null ? null : ['path' => 'item:' . $item->collection . '/' . $item->slug]);

        $r->contentBadge('page', fn (Page $page): ?array => $page->slug === '' ? null : ['label' => 'FX-' . $page->slug, 'tone' => 'warn']);
        $r->contentBadge('collectionItem', fn (CollectionItem $item): array => ['label' => 'FXI-' . $item->slug]);

        $r->command('fixture:hello', function (array $args) use ($r): int {
            $calls = $r->get('fixture.calls');
            echo $this->config('greeting') . ' ' . implode(' ', $args) . ' ' . get_class($calls) . "\n";
            return in_array('--fail', $args, true) ? 3 : 0;
        }, 'Says hello');

        $r->migration('001-things', 'CREATE TABLE fixture_things (id INTEGER PRIMARY KEY, name TEXT)');
    }
}

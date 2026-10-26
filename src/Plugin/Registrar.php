<?php

declare(strict_types=1);

namespace Station0\Plugin;

use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * What a plugin's register() declares. Every method only records; Bootstrap
 * and bin/console apply the declarations (see Plugins).
 *
 * Runtime callbacks (listeners, resolvers, Twig functions, panel data,
 * commands, `when`) reach services through get(), which works once the
 * container is built:
 *
 *   $r->on('page.saved', fn (array $e) => $r->get(MyStore::class)->touch($e['path']));
 *
 * Invalid declarations throw \InvalidArgumentException; the plugin is then
 * logged and skipped as a whole.
 */
final class Registrar
{
    public const TARGET_PATTERN = '/^(page|collectionItem)(?::([^@]+))?(?:@([A-Za-z0-9_-]+))?$/';

    private ?ContainerInterface $container = null;

    /** @var array<string, callable> */
    private array $services = [];
    /** @var list<callable> */
    private array $publicRoutes = [];
    /** @var list<array{routes: callable, role: string}> */
    private array $adminRoutes = [];
    /** @var list<array{middleware: string|MiddlewareInterface|\Closure, when: ?\Closure}> */
    private array $middleware = [];
    /** @var list<array{string, callable}> */
    private array $listeners = [];
    /** @var list<callable> */
    private array $notFound = [];
    /** @var list<array{name: string, callable: callable, options: array}> */
    private array $twigFunctions = [];
    /** @var list<array{name: string, callable: callable, options: array}> */
    private array $twigFilters = [];
    /** @var array<string, mixed> */
    private array $twigGlobals = [];
    /** @var array<string, string> namespace ⇒ dir */
    private array $templates = [];
    /** @var list<array{id: string, label: string, url: string, role: string, icon: ?string}> */
    private array $nav = [];
    /** @var list<array> */
    private array $panels = [];
    /** @var list<array> */
    private array $badges = [];
    /** @var array<string, array{handler: callable, help: string}> */
    private array $commands = [];
    /** @var array<string, string> id ⇒ SQL */
    private array $migrations = [];
    /** @var array<string, array<string, string>> locale ⇒ key ⇒ text */
    private array $translations = [];

    public function __construct(
        private readonly Plugin $plugin,
        private readonly string $adminPath = '/admin',
    ) {}

    public function name(): string
    {
        return $this->plugin->name();
    }

    public function plugin(): Plugin
    {
        return $this->plugin;
    }

    /** URL of the plugin's admin routes: {adminPath}/{name}{path}. */
    public function adminUrl(string $path = ''): string
    {
        return $this->adminPath . '/' . $this->name() . $path;
    }

    // ── Declarations ─────────────────────────────────────────────────────

    /** A container entry; $factory gets the container, like core definitions. */
    public function service(string $id, callable $factory): self
    {
        $this->services[$id] = $factory;
        return $this;
    }

    /**
     * Public routes, registered before the page catch-all:
     * fn (\Slim\Interfaces\RouteCollectorProxyInterface $app) => $app->get('/feed.xml', …)
     */
    public function publicRoutes(callable $routes): self
    {
        $this->publicRoutes[] = $routes;
        return $this;
    }

    /**
     * Admin routes under {adminPath}/{name}, behind the admin sign-in and
     * the role ('admin' always passes): fn ($group) => $group->get('', …)
     */
    public function adminRoutes(callable $routes, string $role = 'admin'): self
    {
        $this->adminRoutes[] = ['routes' => $routes, 'role' => $role];
        return $this;
    }

    /**
     * Global middleware: a container id / class name, an instance, or a Slim
     * closure fn ($request, $handler). It wraps routing (outside it, inside
     * the CSRF guard and visitor middleware); the first declared runs first.
     * $when is called once at boot — false leaves the middleware out entirely.
     */
    public function middleware(string|MiddlewareInterface|\Closure $middleware, ?\Closure $when = null): self
    {
        $this->middleware[] = ['middleware' => $middleware, 'when' => $when];
        return $this;
    }

    /**
     * Content event listener, pattern as for site task hooks (`page.*`,
     * `page.saved:/blog`, `collection.item.saved:products`), see Events.
     *
     * @param callable(array $payload, string $event): mixed $listener
     */
    public function on(string $pattern, callable $listener): self
    {
        $this->listeners[] = [$pattern, $listener];
        return $this;
    }

    /**
     * Consulted before a page (or /media file) 404 / 410 is rendered; the
     * first resolver returning a Response wins.
     *
     * @param callable(\Psr\Http\Message\ServerRequestInterface, \Psr\Http\Message\ResponseInterface, string $path, int $status): ?\Psr\Http\Message\ResponseInterface $resolver
     */
    public function notFound(callable $resolver): self
    {
        $this->notFound[] = $resolver;
        return $this;
    }

    public function twigFunction(string $name, callable $callable, array $options = []): self
    {
        $this->twigFunctions[] = ['name' => $name, 'callable' => $callable, 'options' => $options];
        return $this;
    }

    public function twigFilter(string $name, callable $callable, array $options = []): self
    {
        $this->twigFilters[] = ['name' => $name, 'callable' => $callable, 'options' => $options];
        return $this;
    }

    public function twigGlobal(string $name, mixed $value): self
    {
        $this->twigGlobals[$name] = $value;
        return $this;
    }

    /** Twig namespace for the plugin's templates: templates('redirects', __DIR__ . '/templates') ⇒ @redirects/list.twig */
    public function templates(string $namespace, string $dir): self
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $namespace) || in_array($namespace, ['admin', '__main__'], true)) {
            throw new \InvalidArgumentException("Invalid template namespace '{$namespace}'.");
        }
        $this->templates[$namespace] = $dir;
        return $this;
    }

    /**
     * Admin menu tab. $url is relative to the admin path (null = the plugin's
     * admin base, /{name}); $label may be a translation key. The tab's
     * `data-nav` id is "plugin-{name}" (then -2, -3 … for more tabs) —
     * pass it as `activeNav` from the plugin's pages.
     */
    public function adminNav(string $label, ?string $url = null, string $role = 'admin', ?string $icon = null): self
    {
        $n = count($this->nav);
        $this->nav[] = [
            'id'    => 'plugin-' . $this->name() . ($n > 0 ? '-' . ($n + 1) : ''),
            'label' => $label,
            'url'   => $url ?? '/' . $this->name(),
            'role'  => $role,
            'icon'  => $icon,
        ];
        return $this;
    }

    /**
     * A panel on the page / collection-item edit form. $target: `page`,
     * `page:/blog` (subtree), `page@article` (template), `collectionItem`,
     * `collectionItem:products` (wildcards). $data(?entity, array $user):
     * ?array is called when the form renders (null entity = new, unsaved
     * page / item; null result hides the panel) and must be cheap.
     * $template gets entity, data, csrf, panel and pluginUrl.
     */
    public function contentPanel(string $target, string $id, string $label, string $template, callable $data, ?string $role = null): self
    {
        $this->assertTarget($target);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
            throw new \InvalidArgumentException("Invalid panel id '{$id}'.");
        }
        $this->panels[] = [
            'target'   => $target,
            'id'       => $id,
            'label'    => $label,
            'template' => $template,
            'data'     => $data,
            'role'     => $role,
        ];
        return $this;
    }

    /**
     * Small info on a row in the Structure tree, a group view or a
     * collection's item list. $provider(entity): ?array{label, title?, tone?}
     * runs per row — keep it O(1). tone: info (default) | warn | danger.
     */
    public function contentBadge(string $target, callable $provider, ?string $role = null): self
    {
        $this->assertTarget($target);
        $this->badges[] = ['target' => $target, 'provider' => $provider, 'role' => $role];
        return $this;
    }

    /**
     * `php vendor/bin/console <name> …`; $handler(list<string> $args): ?int
     * gets the arguments after the name and returns the exit code (null = 0).
     */
    public function command(string $name, callable $handler, string $help = ''): self
    {
        if (!preg_match('/^[a-z0-9][a-z0-9:_-]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid command name '{$name}'.");
        }
        $this->commands[$name] = ['handler' => $handler, 'help' => $help];
        return $this;
    }

    /**
     * SQLite schema change, applied once when the database is first opened
     * in a request (tracked in `station0_migrations` as "{name}.{id}").
     * Never edit a shipped migration — add a new id.
     */
    public function migration(string $id, string $sql): self
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id) || isset($this->migrations[$id])) {
            throw new \InvalidArgumentException("Invalid or duplicate migration id '{$id}'.");
        }
        $this->migrations[$id] = $sql;
        return $this;
    }

    /** Admin translations, merged under the core ones: ['en' => [...], 'cs' => [...]]. */
    public function translations(array $byLocale): self
    {
        foreach ($byLocale as $locale => $strings) {
            $this->translations[(string) $locale] = array_merge($this->translations[(string) $locale] ?? [], (array) $strings);
        }
        return $this;
    }

    // ── Runtime ──────────────────────────────────────────────────────────

    /** A container entry — only from runtime callbacks, not from register(). */
    public function get(string $id): mixed
    {
        return $this->container()->get($id);
    }

    public function container(): ContainerInterface
    {
        if ($this->container === null) {
            throw new \LogicException('The container is not built yet — call get() from runtime callbacks only.');
        }
        return $this->container;
    }

    /** @internal Bootstrap / bin/console */
    public function bind(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /** @internal everything declared, keyed by kind (see Plugins) */
    public function declarations(): array
    {
        return [
            'services'      => $this->services,
            'publicRoutes'  => $this->publicRoutes,
            'adminRoutes'   => $this->adminRoutes,
            'middleware'    => $this->middleware,
            'listeners'     => $this->listeners,
            'notFound'      => $this->notFound,
            'twigFunctions' => $this->twigFunctions,
            'twigFilters'   => $this->twigFilters,
            'twigGlobals'   => $this->twigGlobals,
            'templates'     => $this->templates,
            'nav'           => $this->nav,
            'panels'        => $this->panels,
            'badges'        => $this->badges,
            'commands'      => $this->commands,
            'migrations'    => $this->migrations,
            'translations'  => $this->translations,
        ];
    }

    private function assertTarget(string $target): void
    {
        if (!preg_match(self::TARGET_PATTERN, $target)) {
            throw new \InvalidArgumentException("Invalid content target '{$target}' (page, page:/path, page@template, collectionItem, collectionItem:name).");
        }
    }
}

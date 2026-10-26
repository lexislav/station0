# Plugins

> **Experimental** (station0 ≥ 0.10). The API may still change while the
> first two or three plugins are built on it.

A plugin is an optional feature that a site turns on in `site/config.php`.
A plugin that is not listed is never loaded: none of its classes, routes,
middleware or services exist. With an empty list the app does what it
always did.

Plugins are trusted PHP, like site tasks. They are not sandboxed, and there
is no marketplace, auto-discovery or on/off switch in the admin.

## Enabling

```php
// site/config.php
'plugins' => [
    'redirects' => ['log404' => true],          // bundled: src/Plugin/Redirects
    \Acme\Station0Sitemap\Plugin::class => [],  // from a Composer package
    'forms' => false,                           // listed but off
],
```

- **Short name** (`[a-z][a-z0-9_-]*`): a plugin bundled with station0,
  `Station0\Plugin\<StudlyName>\Plugin` (`seo-meta` →
  `Station0\Plugin\SeoMeta\Plugin`).
- **Anything else** is a fully qualified class name, usually from a Composer
  package.
- **Order** in the array is the registration order. It decides the order of
  middleware, 404 resolvers, admin tabs, panels and badges.
- **Options** are the plugin's own config. A `BasePlugin` merges them over
  its `defaults()` and checks them in `configure()`.

A plugin that cannot load is written to `writable/logs/app.log`
(`Plugin '…' skipped: …`) and left out. This happens for an unknown name, a
missing class, a class that is not a `Plugin`, a station0 version mismatch,
or a constructor or `register()` that throws. The rest of the site keeps
running. A plugin whose `register()` throws halfway leaves nothing behind.

`php vendor/bin/console plugins:list` shows the enabled plugins, their
console commands and their migrations.

Bundled plugins:

| Name | What |
|---|---|
| `redirects` | Redirect manager: automatic redirects on slug change / move, rules, 404 log ([redirects.md](redirects.md)) |

## Writing a plugin

`src/Plugin/Redirects/` is a complete example that uses most of the API.

```php
<?php // src/Plugin.php in acme/station0-hello

namespace Acme\Hello;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Station0\Plugin\BasePlugin;
use Station0\Plugin\Registrar;
use Station0\Service\Page;

final class Plugin extends BasePlugin
{
    public function name(): string
    {
        return 'hello';                       // admin routes: /admin/hello/…
    }

    protected function defaults(): array
    {
        return ['greeting' => 'Hello'];
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
        $greeting = $this->config('greeting');

        $r->service(Greeter::class, fn ($c) => new Greeter($c->get(\PDO::class), $greeting));

        $r->publicRoutes(function ($app) use ($r) {
            $app->get('/hello', function (Request $request, Response $response) use ($r) {
                $response->getBody()->write($r->get(Greeter::class)->greet());
                return $response;
            });
        });

        $r->templates('hello', __DIR__ . '/../templates');
        $r->adminRoutes(function ($group) use ($r) {
            $group->get('', fn ($request, $response) => $r->get(\Slim\Views\Twig::class)
                ->render($response, '@hello/admin.twig', ['activeNav' => 'plugin-hello']));
        }, 'editor');
        $r->adminNav('hello_tab', null, 'editor', '👋');
        $r->translations(['en' => ['hello_tab' => 'Hello'], 'cs' => ['hello_tab' => 'Ahoj']]);

        $r->on('page.saved', fn (array $event) => $r->get(Greeter::class)->remember($event['path']));
        $r->migration('001-greetings', 'CREATE TABLE hello_greetings (path TEXT PRIMARY KEY, at TEXT)');
        $r->command('hello:stats', function (array $args) use ($r): int {
            echo $r->get(Greeter::class)->count() . "\n";
            return 0;
        }, 'Counts the greeted pages');

        $r->contentBadge('page', fn (Page $page) => $r->get(Greeter::class)->seen($page->urlPath)
            ? ['label' => '👋', 'title' => 'Greeted'] : null);
    }
}
```

### The contract

```php
interface Station0\Plugin\Plugin
{
    public function name(): string;                 // [a-z][a-z0-9-]*, unique, not a core admin path (pages, users …)
    public function register(Registrar $r): void;   // declare everything; no I/O
}
```

The class is constructed with its options array: `new $class($options)`.
Extend `Station0\Plugin\BasePlugin` for `defaults()`, `configure()` and
`config($key)`.

### Rules for `register()`

- **Declare only.** `register()` runs on every request for every enabled
  plugin, the same rule as the top level of a task file. Do not read files,
  open the database or call services there. Build heavy things in services.
  They are lazy and built only when something asks for them.
- **Reach services at runtime through `$r->get($id)`.** It works in every
  callback that runs after boot: routes, listeners, resolvers, Twig
  functions, panel data, badges, commands and `when`. It does not work
  inside `register()` itself.
- **Slim binds route and middleware closures to the container.** Inside
  them, `$this` is the container, not your plugin. Capture what you need
  with `use`.
- **Plugin services cannot replace core ones.** A `service()` id that the
  core already defines is logged and ignored.

## Registrar

| Method | Effect |
|---|---|
| `service(id, factory)` | PHP-DI definition. `factory` gets the container, as in `Bootstrap` |
| `publicRoutes(fn ($app))` | public routes, registered before site actions and the page catch-all |
| `adminRoutes(fn ($group), role = 'admin')` | routes under `{adminPath}/{name}`, behind the admin sign-in and `RoleMiddleware` (admin always passes) |
| `middleware(mw, when = null)` | global middleware (see below) |
| `on(pattern, fn (array $payload, string $event))` | content event listener (see [Events](#events)) |
| `notFound(fn ($request, $response, string $path, int $status): ?Response)` | runs before a page 404/410 or a `/media` 404 is rendered. The first non-null response wins |
| `twigFunction(name, callable, options)`, `twigFilter(…)`, `twigGlobal(name, value)` | for site and admin templates |
| `templates(namespace, dir)` | `@namespace/…` templates |
| `adminNav(label, url = null, role = 'admin', icon = null)` | admin menu tab. `url` is relative to the admin path (`null` = `/{name}`). `label` may be a translation key. Its `data-nav` id is `plugin-{name}` (`-2`, `-3` … for more), so pass `activeNav: 'plugin-{name}'` from your pages |
| `contentPanel(target, id, label, template, data, role = null)` | panel on the page or collection-item edit form (see below) |
| `contentBadge(target, provider, role = null)` | small label on a list row (see below) |
| `command(name, fn (array $args): ?int, help)` | `php vendor/bin/console {name} …`. It gets the arguments after the name and returns the exit code (`null` = 0) |
| `migration(id, sql)` | SQLite schema change, applied once (see below) |
| `translations(['en' => […], 'cs' => […]])` | merged into the admin's `t`. Core keys win, so prefix yours (`hello_…`) |

`$r->adminUrl('/x')` returns `{adminPath}/{name}/x`.

### Middleware

The middleware can be a container id or class name (resolved lazily), a PSR-15
instance, or a Slim closure `fn ($request, $handler)`. Plugin middleware
wraps routing. It sits inside the CSRF guard and the visitor middleware, so
error pages, sessions and `visitor` are already set up. The first declared
middleware runs first.

`when` is called **once at boot**. When it returns false, the middleware is
not added at all, so it costs nothing on that request. The redirect manager
uses this to add its middleware only when force rules exist:

```php
$r->middleware(ForceRedirects::class, fn () => $r->get(RuleSet::class)->hasForce());
```

### Content panels

Plugins often keep data *about* a page or a collection item outside its
front matter, such as redirects that point to it, form submissions or SEO
checks. A panel shows that data on the item's own edit form.

```php
$r->contentPanel('page', 'redirects', 'redirects_panel', '@redirects/panel.twig',
    fn (?Page $page, array $user): ?array => $page === null
        ? ['new' => true]                                   // unsaved page
        : $r->get(RedirectIndex::class)->incoming($page->urlPath));
```

- **Targets.** `page` matches every page, `page:/blog` matches /blog and its
  subtree, and `page@article` matches pages with that template. These can be
  combined: `page:/blog@article`. For collection items, `collectionItem`
  matches every item and `collectionItem:products` matches one collection
  (wildcards allowed).
- **`data(?entity, array $user): ?array`** is called each time the edit form
  renders, so keep it cheap: read a precomputed index, don't scan
  everything. Returning `null` hides the panel. On a new, unsaved page or
  item, `entity` is `null`. Narrowed targets then never match, and plain
  ones can show "available after the first save". `$user` holds `id`,
  `email`, `roles` (names) and `isAdmin`.
- **The template** gets `entity`, `data`, `csrf`, `panel` (`plugin`, `id`,
  `label`) and `pluginUrl` (`{adminPath}/{name}`).
- **Placement.** Panels render as collapsible sections below the edit form,
  in registration order.
- **Panels are self-contained.** A panel does not take part in the form's
  save. It acts through the plugin's own admin routes, so a plugin cannot
  break saving a page. Values that must be saved with the page belong in
  page fields. The page includes a small helper that POSTs with the admin's
  CSRF fields:

  ```js
  station0.post(pluginUrl + '/rules', { from: '/old', to: '/new' })
      .then(r => r.json()).then(…);
  ```

- **Errors.** A panel whose `data()` or template throws shows "Panel failed
  to load", and the error goes to the log. The rest of the form still works.
- **Roles.** `role` hides the panel from users without that role. Admin
  always passes.

### Content badges

```php
$r->contentBadge('page', fn (Page $page): ?array =>
    ($n = $r->get(RedirectIndex::class)->count($page->urlPath)) > 0
        ? ['label' => "↪ {$n}", 'title' => "{$n} redirects", 'tone' => 'info']
        : null);
```

Badges appear on rows in the Structure tree, in group views, in stream
record lists and in a collection's item list. The provider runs **once per
row**, so it must be O(1) against a cached index. `tone` is `info`
(default), `warn` or `danger`.

### Migrations

```php
$r->migration('001-log', 'CREATE TABLE hello_log (path TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0)');
```

Pending migrations are applied when the SQLite connection is first opened in
a request or console run. This happens only if some enabled plugin declares
migrations. Each one runs in a transaction and is recorded in
`station0_migrations` as `{plugin}.{id}`. A migration that fails is logged
and retried on the next connection. Never edit a shipped migration; add a
new id.

## Events

The admin dispatches content events into `Station0\Support\Events` after a
change is stored. Plugin listeners run first. Site tasks subscribed with
`on` run after them, through `TaskHooks`, which is one listener for
everything. The event names and payloads are the ones task hooks use:

| Event | Payload |
|---|---|
| `page.saved` | `path`, `title`, `template`, `status`, `created`, `previous_path` (renamed) |
| `page.moved` | `path`, `previous_path` |
| `page.deleted` | `path`, `title`, `template`, `status` |
| `collection.item.saved` | `collection`, `slug`, `title`, `created`, `previous_slug` (renamed) |
| `collection.item.deleted` | `collection`, `slug`, `title` |

Patterns: `page.*`, `*`, `page.saved:/blog` (path prefix, `previous_path`
counts too) and `collection.item.saved:prod*`. A listener that throws is
logged, and the admin action still succeeds. Changes made by tasks or
plugins through the repositories fire no events.

## Composer packages

```json
{
    "name": "acme/station0-hello",
    "type": "station0-plugin",
    "autoload": { "psr-4": { "Acme\\Hello\\": "src/" } },
    "extra": { "station0": { "requires": "^0.10" } }
}
```

When the installed station0 is a tagged release that does not satisfy
`extra.station0.requires`, the plugin is skipped with a log line. Supported
constraints are `^`, `~`, comparisons, `x.y.*`, spaces or commas for AND,
and `||`. Dev installs (`dev-main`) skip the check.

## Not in this version

- Moving existing features (tasks, actions, members-only access,
  thumbnails) into plugins.
- Plugin-provided block types. Blocks stay site templates.
- Panels that join the page form's save.

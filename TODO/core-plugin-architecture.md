---
title: Minimal plugin architecture — optional features enabled per site, zero cost when off (first consumer: redirects)
status: open
priority: medium
area: core
created: 2026-10-26
related: [TODO/content-redirect-manager.md, src/Bootstrap.php (buildContainer, registerRoutes, registerActions), bin/console, admin/templates/layout.twig, src/Service/TaskHooks.php]
---

## Problem

Every feature is wired by hand in `Bootstrap` (container definitions, Twig
functions, routes, middleware) and `bin/console` (a `switch` of commands);
the admin menu is hard-coded in `layout.twig`. Optional features each invent
their own switch (`access.mode`, `thumbs.static`, `tasks.runner`) and still
register their services and routes on every site. There is no way to add a
feature — from the core, a site, or a Composer package — without editing
those files, and no way to keep a feature completely out of a site that does
not use it.

Content events exist only as `TaskHooks::dispatch()` calls from the admin
controllers, so nothing but site tasks can react to `page.saved` etc.

## Why it matters / why now

The redirect manager (`TODO/content-redirect-manager.md`) needs exactly the
pieces a plugin API would provide — conditional routes and middleware, an
admin tab, console commands, a DB table, content-event listeners, a hook into
the 404 path — and must cost nothing when disabled. Building it as the first
plugin gives the API a real consumer instead of designing it in the abstract.
Likely next candidates: forms/contact, sitemap.xml + robots, search index,
SEO meta, analytics snippet.

## Proposed fix

Keep it small and explicit — no marketplace, no auto-discovery, no admin
on/off UI, no sandboxing (plugins are trusted PHP, like site tasks).

### Enabling

```php
// site/config.php
'plugins' => [
    'redirects' => ['log404' => true],          // bundled: src/Plugin/Redirects
    \Acme\Station0Sitemap\Plugin::class => [],  // Composer package
],
```

- Short names resolve to bundled plugins in `src/Plugin/<Name>/Plugin.php`;
  anything else is a FQCN. Not listed ⇒ the class is never loaded.
- Order in the array = registration order (middleware, 404 resolvers, nav).
- The options array is validated by the plugin and merged over its defaults;
  available as `$plugin->config()`.

### Contract

```php
namespace Station0\Plugin;

interface Plugin
{
    public function name(): string;                 // 'redirects'
    public function register(Registrar $r): void;   // declare everything; no I/O
}
```

`Registrar` methods, each mapping onto what Bootstrap already does by hand:

| Method | Effect |
|---|---|
| `service(id, factory)` | PHP-DI definition |
| `publicRoutes(fn(RouteCollectorProxy))` | registered before the catch-all page route |
| `adminRoutes(fn, role)` | inside the authed admin group, with `RoleMiddleware` |
| `middleware(classOrFactory, when?)` | global middleware; `when` = closure evaluated once at boot (lets redirects skip its middleware when there are no force rules) |
| `on(event, listener)` | content events (see below) |
| `notFound(resolver)` | chain consulted by `PageController::notFound()` / `AssetController` before rendering a 404/410; first non-null `Response` wins |
| `twigFunction` / `twigFilter` / `twigGlobal` | public + admin Twig |
| `templates(namespace, dir)` | e.g. `@redirects/list.twig` for admin views |
| `adminNav(label, url, role, icon?)` | rendered in `layout.twig` via a `plugin_nav()` Twig function |
| `contentPanel(target, id, label, template, data, role?)` | a panel on the page / collection-item edit form (see below) |
| `contentBadge(target, provider, role?)` | small info on a row in the Structure tree / group view / item list (e.g. "3 redirects") |
| `command(name, callable, help)` | `bin/console` dispatches unknown commands here |
| `migration(id, sql)` | SQLite schema, applied once, tracked in `station0_migrations` |
| `translations(locale => array)` | merged into `t` |

`register()` only declares; it runs on every request for enabled plugins, so
it must stay side-effect free (same rule as task files). Heavy work belongs in
lazily-built services.

### Content panels and badges

Plugins often hold data *about* a piece of content that lives outside its
front matter — redirects pointing to a page, form submissions from it, its
search-index status, SEO checks. The editor should see and manage that on the
content itself, not only in the plugin's own tab.

- `target` = `page` | `collectionItem` (optionally narrowed: `page:/blog`,
  `page@article` by template, `collectionItem:products`).
- `data(Page|CollectionItem $entity, $user): ?array` — called when the edit
  form renders; `null` hides the panel. Must be cheap (it runs on every edit
  form open) — read precomputed indexes, not scan everything.
- The panel template (`@<plugin>/…`) is rendered collapsible below the page
  fields / visibility panel, in registration order, with `entity`, `data`,
  `csrf` and the plugin's admin base URL.
- **Self-contained in v1:** a panel acts through the plugin's own admin routes
  (`fetch` with the CSRF fields) and does not take part in the page form's
  save, so plugins can't break saving a page and the page form needs no
  plugin-specific parsing. Values that must be saved *with* the page belong
  in page fields. (Joining the form submit — fields posted with the page and
  handed to the plugin on `page.saved` — is a possible later extension.)
- A new, unsaved page has no URL / identity yet: panels get `entity = null`
  and either hide or show "available after the first save".
- `contentBadge` providers return `?{label, title?, tone?}` per entity for
  lists; they are called per row, so they must be O(1) against a cached index.
- `role` hides a panel / badge from users without it (admin always passes).

### Events

Introduce a tiny `Station0\Support\Events` dispatcher (array of listeners per
name, wildcard support reused from `TaskHooks`). Admin controllers dispatch
into it; `TaskHooks` becomes one listener of it, so task hook behavior and
event names/payloads (`page.saved`, `page.moved`, `page.deleted`,
`collection.item.*`) stay unchanged. A failing listener is logged and never
breaks the admin action — same contract as hooks today.

### Bootstrap / console

- `Bootstrap::plugins($config)` instantiates enabled plugins once; used by
  both `createApp()` and `bin/console` (like `thumbService()` is shared).
- Unknown plugin name / missing class / failing `register()` ⇒ logged error
  and the plugin is skipped (site keeps running), mirroring `ActionRegistry`.
- Composer packages: `type: station0-plugin`, optional
  `extra.station0.requires: "^0.10"` checked with
  `Composer\InstalledVersions` — mismatch ⇒ skipped with a log line.

### Non-goals for the first version

- Not moving existing features (tasks, actions, access, thumbs) into plugins.
  Possible later; the API should be shaped so it could.
- No plugin-provided block types (blocks stay site templates).
- API marked `@experimental` in docs until two or three plugins use it.

## Done when

- `plugins` config loads bundled + FQCN plugins; unlisted plugins are never
  autoloaded (test with a fixture plugin that throws on construction).
- Each `Registrar` method covered by a test with a fixture plugin (route
  reachable, admin route role-guarded, middleware present only when `when`
  returns true, nav item rendered, console command runs, migration applied
  once, event listener called, notFound resolver short-circuits the 404,
  content panel rendered on the page and collection-item edit forms with
  the entity's data and hidden for a `null` result / missing role / new
  page, badge shown in the Structure tree row).
- `TaskHooks` runs through `Events`; existing task hook tests pass unchanged.
- Broken plugin is logged and skipped, app still boots.
- No measurable overhead with `plugins` empty.
- `docs/plugins.md` (contract, example plugin, rules for `register()`), a
  CLAUDE.md section; redirect manager ships as the first bundled plugin.

# Station0 — CLAUDE.md

## Project overview

This repo is the **core library** (`lexislav/station0`, type: library).
It ships into `vendor/lexislav/station0/` when the skeleton project runs `composer install`.

The companion skeleton is at `lexislav/get-station0` (separate repo).

Flat-file CMS built on **Slim 4**, **Twig 3**, **League/CommonMark**, and **Delight\Auth** (SQLite).

## Key directories (this repo)

| Path | Purpose |
|---|---|
| `src/` | PHP source (namespace `Station0\`) |
| `admin/templates/` | Admin Twig templates |
| `bin/console` | CLI binary — exposed via `composer bin`, runs as `php vendor/bin/console` |
| `config/roles.php` | Role definitions |

## Architecture

**Request flow:** `public/index.php` (skeleton) → dotenv → `Bootstrap::createApp()` → Slim app with PHP-DI container → middleware stack → controller → Twig response.

**Path resolution:** `Bootstrap::findProjectRoot()` checks if `basename(dirname(dirname($packageRoot))) === 'vendor'` (real Composer install → 3 levels up). For symlinked dev installs and direct clones falls back to `getcwd()`, which resolves to the skeleton root when the server is started from there.

**Config factory:** The skeleton provides `site/config.php` as `fn(string $station0Root, string $siteRoot, string $projectRoot): array`. Called by both Bootstrap and bin/console.

**Content** is stored in `site/content/pages/{slug}/page.txt` with Kirby-style front matter:

```
Title: Page title
Published: true
---
Markdown body (or YAML block list)
```

**Block system:** Each block type lives in `site/templates/blocks/{type}/` (skeleton) with `schema.yaml` and `template.twig`.

**Caching:** `FileCache` (SHA-1 keyed `.cache` files in `writable/cache/`). Invalidated on page save or `cache:clear`.

**First-run setup:** `AuthMiddleware` detects empty `users` table → redirects to `/admin/setup`. `SetupController` creates the first admin account and logs them in. Once any user exists, `/admin/setup` redirects to `/admin/login`.

## PHP namespace & package

- Namespace: `Station0\`
- Composer package: `lexislav/station0` (type: library)
- PHP ≥ 8.2 required

## Middleware stack (admin routes)

1. `RoutingMiddleware`
2. `TwigMiddleware`
3. `Guard` (CSRF)
4. `ErrorMiddleware`
5. `AuthMiddleware` — redirects to `/admin/setup` (no users) or `/admin/login` (not logged in)
6. `RoleMiddleware` — role check (`admin` / `editor`)

## Key classes

| Class | File | Purpose |
|---|---|---|
| `Bootstrap` | `src/Bootstrap.php` | DI setup, routing, middleware, path resolution |
| `SetupController` | `src/Controller/Admin/SetupController.php` | First-run admin account creation |
| `ContentRepository` | `src/Service/ContentRepository.php` | Flat-file CRUD, front-matter parsing |
| `Page` | `src/Service/Page.php` | Page entity |
| `PageRenderer` | `src/Service/PageRenderer.php` | Markdown + block rendering, cache |
| `BlockRegistry` | `src/Service/BlockRegistry.php` | Block schema/template loading; `defaults()` builds a seeded block from schema defaults |
| `TemplateBlocks` | `src/Service/TemplateBlocks.php` | Resolves per-template `allowedBlocks` / `defaultBlocks` from `<template>.blocks.yaml` |
| `UserRepository` | `src/Service/UserRepository.php` | Wrapper around Delight\Auth |
| `FileCache` | `src/Service/FileCache.php` | File-based cache (get/set/flush) |
| `MediaService` | `src/Service/MediaService.php` | Page-local asset storage + ref resolution; also serves collection assets via `_collections/` prefix |
| `AssetController` | `src/Controller/AssetController.php` | Serves `/media/{path:.+}` |
| `Slug` | `src/Support/Slug.php` | URL slug + filename slugification |
| `CollectionItem` | `src/Service/CollectionItem.php` | Headless content item entity (no URL) |
| `CollectionRepository` | `src/Service/CollectionRepository.php` | CRUD for Collections + items, reads `_collection.yaml` schemas |
| `CollectionController` | `src/Controller/Admin/CollectionController.php` | Admin CRUD for collections and items |
| `CollectionGroups` | `src/Service/CollectionGroups.php` | Groups collections into admin menu tabs (`group:` + `_groups.yaml`), role access |

## Local development

To test the library against a real skeleton:

```bash
# In skeleton's composer.json, use path repository:
{
  "repositories": [{"type": "path", "url": "../station0"}],
  "require": {"lexislav/station0": "*@dev"}
}
composer install
```

## Block schema format

`site/templates/blocks/{type}/schema.yaml` uses a dict of fields keyed by name. `PageController::normalizeFields()` converts it to an array with `name` injected, and `item:` to `item_fields:` for list fields.

```yaml
label: Gallery
fields:
  columns:
    type: number
    label: Columns
    default: 3
  images:
    type: list
    label: Images
    item:
      src:
        type: image   # renders upload button in admin
        label: Image
      alt:
        type: text
        label: Alt text
```

Supported field types: `text`, `textarea`, `image`, `number`, `select`, `boolean`, `list`.

### Select options (`FieldOptions`)

`select` options are resolved by `Station0\Service\FieldOptions::resolveFields()`
(called from `PageController::blockTypeData()` and `CollectionController`'s form
actions, recursing into list `item_fields`). Each select gets `options`
(`[{value,label,group}]`) and `option_groups` (`[{label, options}]`); the admin
renders them through `admin/templates/_select_options.twig` (server) and
`selectOptionsHtml()` in `pages/edit.twig` (new list items, JS).

- Static: `options: [a, b]` | `{a: A}` | `[{value, label, group}]`.
- Data source: `options_from: collection:<name>` + optional `group_by`,
  `sort_by` (`-field` = desc, numeric-aware incl. decimal comma),
  `option_label` (`{title}`, `{slug}`, `{<extra field>}`), `placeholder`.
  Value stored = item slug. Data-source selects get an empty `—` choice by default.
- `FieldOptions::initialValue()` is the select default used by
  `BlockRegistry::fieldDefault()`: explicit `default`, else `''` for
  data-source/placeholder selects, else the first static option.
- A stored value missing from the options is kept as a "(not found)" option.

## Per-template block restrictions

A template can restrict which block types its pages may use, and pre-seed a new
page with starter blocks. Configuration is a **per-template manifest** that sits
beside the template file:

```
site/templates/gallery.twig         ← the template
site/templates/gallery.blocks.yaml  ← its block manifest (optional)
```

```yaml
# site/templates/gallery.blocks.yaml
allowedBlocks:        # restrict the "+ Add block" palette to these types, in order
  - text
  - gallery
defaultBlocks:        # pre-insert these into a NEW page of this template, in order
  - gallery
```

Both keys are optional and **compose**:

- **`allowedBlocks`** — the "+ Add block" palette (and the JS block templates /
  schemas it emits) shows only these types, in declared order. Applied at every
  `PageController::blockTypeData()` call site (new / create-error / edit /
  update-error). **No `allowedBlocks` ⇒ all blocks available** (the historic
  behavior). Unknown block names are dropped; if filtering empties the list,
  it falls back to the full palette rather than rendering an empty one.
- **`defaultBlocks`** — applied **only to new pages**: each listed type is
  pre-inserted using its schema's default field values (via
  `BlockRegistry::defaults()`, which shares the default-resolution logic with
  `snippet()`), so a seeded block matches a manually added one. **No
  `defaultBlocks` ⇒ a single empty `text` block** (the historic default).
  Unknown names are dropped, and any default block **must be a member of the
  effective allow-list** — non-members are dropped.

Resolution lives in `Station0\Service\TemplateBlocks` (keyed/cached by template
name). This is the block-level analogue of a page's **`AllowedChildTemplates`**
front-matter field: `AllowedChildTemplates` restricts which *templates* a child
*page* may use; `allowedBlocks` restricts which *block types* a page *body* may
contain. The former is per-page front matter (parent → child); the latter is
per-template manifest, which is why pre-seeding (inherently a property of the
template, evaluated before the page exists) lives there too.

**Worked example — a gallery-oriented template.** `site/templates/gallery.blocks.yaml`
with `allowedBlocks: [text, gallery]` and `defaultBlocks: [gallery]` means: a new
gallery page opens with one `gallery` block already inserted (columns/images at
their schema defaults), and the palette offers only "text" and "gallery".

Note: the palette is server-rendered for the template the form opens with;
changing the template `<select>` on a new page does not re-fetch the palette.

## File uploads & media

Assets are stored **page-locally** — directly inside the page's content directory:

```
site/content/pages/about/
  page.txt
  team-photo.jpg
```

`MediaService` owns the storage logic; `UploadController` is a thin HTTP layer
that delegates to it. Uploads accept: **jpg, png, gif, webp, svg** (max 8 MB).
SVG detection sniffs file content for `<svg xmlns` because `mime_content_type()`
misidentifies SVGs as `text/plain`/`text/html`.

**Stored references are page-relative.** Image fields and markdown image links
hold a bare filename (`team-photo.jpg`), not a URL. `PageRenderer` resolves
them at render time:

- For block image fields: walks the block schema, finds `image` fields
  (including those nested in list `item_fields`), and rewrites bare values via
  `MediaService::resolveRef()`.
- For markdown image links in `text` blocks: regex rewrite of `![alt](src)`
  before CommonMark conversion.

This keeps content stable across page moves — when the directory is renamed,
the assets travel with it and references still resolve.

**Public asset URL:** `/media/{page-url-path}/{filename}` (root page uses
`/media/~/{filename}`). Served by `AssetController` with proper Content-Type
and `Cache-Control: immutable`. Route registered just before the catch-all
page route in `Bootstrap`.

**Upload contract:** `POST /admin/upload` requires the form field `pagePath`
(URL path of the target page). Response: `{ filename, url }`. The admin JS
stores `filename` in image inputs and inserts it into ToastUI markdown so
references stay page-local.

**Migration command:** `php vendor/bin/console assets:relink [--dry-run]`
walks every page and replaces `/media/{this-page-path}/file.ext` → `file.ext`.
Cross-page references (`/media/other-page/...`) are left intact.

When deleting a page, `ContentRepository::delete()` removes sibling asset
files but never sub-directories (those belong to child pages).

## Collections (headless content stores)

**Collections** are content stores with no public URL and no place in the page tree. Editors manage items through the admin; webdesigners load them in Twig templates via `collection()` and `collection_item()`.

**Key distinction from Streams:** Streams are routable content sections (a page with `AllowedChildTemplates`). Collections are headless — they have no URL at all.

**Storage layout:**
```
site/content/collections/
  banners/
    _collection.yaml      ← optional schema + label
    summer-sale/
      item.txt            ← same Kirby front matter format as pages
      bg.jpg              ← page-local assets
  shared-blocks/
    intro-cta/
      item.txt
```

**`_collection.yaml` schema format** (mirrors block `schema.yaml`):
```yaml
label: Banners
fields:
  subtitle:
    type: text
    label: Subtitle
  cta_url:
    type: text
    label: CTA URL
  image:
    type: image
    label: Background Image
```
Supported field types: `text`, `textarea`, `image`, `number`, `select`, `boolean`, `color`. Schema is optional — without it, items are free-form (title + body only).

**Twig functions (registered in Bootstrap):**
```twig
collection('banners')                    {# → CollectionItem[]  (published only) #}
collection_item('banners', 'summer-sale') {# → ?CollectionItem #}
render_collection_item(item)             {# → HTML string (markdown or blocks, is_safe html) #}
```

**Item body:** Same markdown or YAML block-list format as pages. `render_collection_item()` reuses `PageRenderer` internally, using a virtual URL path `_collections/{name}/{slug}` for asset resolution.

**Asset URLs:** `/media/_collections/{collection}/{slug}/{filename}`. Served by `AssetController` via the extended `MediaService::resolveAsset()` which detects the `_collections/` prefix. Upload endpoint: `POST /admin/upload-collection` (fields: `collectionName`, `itemSlug`, `file`).

**Admin routes:**
```
GET  /admin/collections                           → list ungrouped collections
GET  /admin/collection-groups/{group}             → list collections of a group
GET  /admin/collections/{name}                    → item list
GET  /admin/collections/{name}/new                → create form
POST /admin/collections/{name}/create             → store
GET  /admin/collections/{name}/{slug}/edit        → edit form
POST /admin/collections/{name}/{slug}/update      → update
POST /admin/collections/{name}/{slug}/delete      → delete
POST /admin/upload-collection                     → asset upload
```

### Collection groups

`group: Shop` in `_collection.yaml` puts the collection into its own admin
menu tab instead of the generic "Collections" tab (which only lists ungrouped
collections and disappears when none are left). Group id =
`Slug::sanitize(group)`. Optional central `collections/_groups.yaml`, keyed by
id, adds `label`, `icon` (text/emoji or inline `<svg>`), `roles` (list of role
names from `config/roles.php`; admin always passes) and tab order (declaration
order, inline-only groups after, by label). Empty groups get no tab.

- `CollectionGroups` builds the groups; its role checker closure is wired in
  `Bootstrap` from `Auth` + `$roles` (null = unrestricted, used in tests).
- `collection_groups()` Twig function feeds `layout.twig`; each group gets a
  `url` — straight to `/collections/{name}` for single-collection groups.
- `CollectionController` returns 403 on every `{name}` action (incl. upload)
  when the collection's group denies the user, and passes
  `activeNav` (`group-{id}` / `collections`) + `backUrl`/`backLabel` to views.

## Common gotchas

- `writable/` and `public/uploads/` live in the **skeleton project root**, not in `vendor/`. Paths come from `$projectRoot` in `site/config.php`.
- `bin/console` is exposed via `composer bin` — users run `php vendor/bin/console`.
- CSRF token: pass `csrf.nameKey / csrf.valueKey / csrf.name / csrf.value` from controllers to templates.
- `Published: false` pages are hidden on the public site but visible in admin.
- Block types are defined in the skeleton's `site/templates/blocks/`, not in the library.
- The setup route (`/admin/setup`) self-disables once any user exists.
- For local dev with symlinked library: always start the server from the skeleton dir — `findProjectRoot()` relies on `getcwd()`.

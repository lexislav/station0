# Station0 — CLAUDE.md

## Project overview

This repo is the **core library** (`lexislav/station0`, type: library).
It ships into `vendor/lexislav/station0/` when the skeleton project runs `composer install`.

The companion skeleton is at `lexislav/station0-skeleton` (separate repo).

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

**Path resolution:** `Bootstrap::findProjectRoot()` walks up 3 levels from `vendor/lexislav/station0/` to find the project root. Falls back to `dirname($packageRoot)` for local dev without vendor.

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
| `BlockRegistry` | `src/Service/BlockRegistry.php` | Block schema/template loading |
| `UserRepository` | `src/Service/UserRepository.php` | Wrapper around Delight\Auth |
| `FileCache` | `src/Service/FileCache.php` | File-based cache (get/set/flush) |
| `Slug` | `src/Support/Slug.php` | URL slug generation |

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

## Common gotchas

- `writable/` and `public/uploads/` live in the **skeleton project root**, not in `vendor/`. Paths come from `$projectRoot` in `site/config.php`.
- `bin/console` is exposed via `composer bin` — users run `php vendor/bin/console`.
- CSRF token: pass `csrf.nameKey / csrf.valueKey / csrf.name / csrf.value` from controllers to templates.
- `Published: false` pages are hidden on the public site but visible in admin.
- Block types are defined in the skeleton's `site/templates/blocks/`, not in the library.
- The setup route (`/admin/setup`) self-disables once any user exists.

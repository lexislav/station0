# Station0 — CLAUDE.md

## Project overview

Flat-file CMS built on **Slim 4**, **Twig 3**, **League/CommonMark**, and **Delight\Auth** (SQLite).
Pages live as `.txt` files on disk; SQLite is used only for authentication.

## Running locally

```bash
composer install
php -S localhost:8080 -t public public/index.php
```

On a fresh install with no users, visit `http://localhost:8080/admin` — the setup flow creates the first admin account in the browser. Alternatively via CLI:

```bash
php station0/bin/console user:create admin admin@example.com admin
```

## Key directories

| Path | Purpose |
|---|---|
| `station0/src/` | PHP source (namespace `Station0\`) |
| `station0/config/` | `app.php` (legacy CLI config), `roles.php` |
| `station0/admin/templates/` | Admin Twig templates |
| `station0/bin/console` | CLI tool |
| `station0/writable/` | Cache, sessions, logs, `db.sqlite` |
| `site/config.php` | App config factory (env-driven, used by web app) |
| `site/content/pages/` | Flat-file pages (`page.txt` per dir) |
| `site/templates/` | Site Twig templates + block definitions |
| `public/` | Entry point + static assets |

## Architecture

**Request flow:** `public/index.php` → dotenv → `Bootstrap::createApp()` → Slim app with PHP-DI container → middleware stack → controller → Twig response.

**DI container** is built in `station0/src/Bootstrap.php`. All services and controllers are registered there.

**Config** is split: `site/config.php` is a factory `fn(string $station0Root, string $siteRoot): array` used by the web app. `station0/config/app.php` is the legacy config used only by `bin/console`. Both resolve to the same DB path.

**Content** is stored in `site/content/pages/{slug}/page.txt` with Kirby-style front matter:

```
Title: Page title
Published: true
---
Markdown body (or YAML block list)
```

URL mapping: `site/content/pages/about/team/page.txt` → `/about/team`.

**Block system:** Page body can be a YAML list of blocks. Each block type lives in `site/templates/blocks/{type}/` with a `schema.yaml` (field definitions for admin UI) and `template.twig`.

**Caching:** `FileCache` (SHA-1 keyed `.cache` files in `station0/writable/cache/`). Invalidated on page save or `cache:clear`. Twig compiled templates go to `writable/cache/twig/` (disabled in debug mode).

**First-run setup:** On a fresh install with no users in the DB, `AuthMiddleware` detects the empty `users` table and redirects all admin routes to `/admin/setup` instead of `/admin/login`. `SetupController` creates the admin user and logs them in immediately. Once any user exists, `/admin/setup` redirects to `/admin/login` and cannot be used again.

## PHP namespace & package

- Namespace: `Station0\`
- Composer package: `lexislav/station0`
- PHP ≥ 8.2 required

## Middleware stack (admin routes)

1. `RoutingMiddleware`
2. `TwigMiddleware`
3. `Guard` (CSRF)
4. `ErrorMiddleware`
5. `AuthMiddleware` — redirects to `/admin/setup` (no users) or `/admin/login` (not logged in)
6. `RoleMiddleware` — role check (`admin` / `editor`)

## CLI commands

```bash
php station0/bin/console user:create <username> <email> [role]
php station0/bin/console user:reset-password <email>
php station0/bin/console cache:clear
php station0/bin/console help
```

## Key classes

| Class | File | Purpose |
|---|---|---|
| `Bootstrap` | `station0/src/Bootstrap.php` | DI setup, routing, middleware |
| `SetupController` | `station0/src/Controller/Admin/SetupController.php` | First-run admin account creation |
| `ContentRepository` | `station0/src/Service/ContentRepository.php` | Flat-file CRUD, front-matter parsing |
| `Page` | `station0/src/Service/Page.php` | Page entity |
| `PageRenderer` | `station0/src/Service/PageRenderer.php` | Markdown + block rendering, cache |
| `BlockRegistry` | `station0/src/Service/BlockRegistry.php` | Block schema/template loading |
| `UserRepository` | `station0/src/Service/UserRepository.php` | Wrapper around Delight\Auth |
| `FileCache` | `station0/src/Service/FileCache.php` | File-based cache (get/set/flush) |
| `Slug` | `station0/src/Support/Slug.php` | URL slug generation |

## Tests

PHPUnit is in `require-dev` but no test suite exists yet. To add tests, create a `tests/` directory and configure `phpunit.xml`.

## Environment variables

```
BASE_URL=http://localhost:8080
DEBUG=true
ADMIN_PATH=/admin
HTTPS=false
SITE_PATH=          # optional; defaults to ../site relative to station0/
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM=
MAIL_FROM_NAME=
MAIL_ENCRYPTION=tls
```

## Common gotchas

- After renaming or moving the project directory, restart the PHP dev server (old instance breaks static file paths).
- CSRF token is required on all `POST` forms — use `csrf.nameKey`/`csrf.valueKey`/`csrf.name`/`csrf.value` variables passed from controllers.
- `Published: false` pages are hidden on the public site but visible in the admin.
- Block schemas define the admin form fields; adding a new block type = new dir under `site/templates/blocks/`.
- `bin/console` loads `.env` via dotenv and uses `site/config.php` — same env var names as the web app (`BASE_URL`, not `APP_BASE_URL`).
- The setup route (`/admin/setup`) is self-disabling: it returns 302 to `/admin/login` once any user exists in the DB.

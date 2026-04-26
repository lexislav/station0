# Station0 — CLAUDE.md

## Project overview

Flat-file CMS built on **Slim 4**, **Twig 3**, **League/CommonMark**, and **Delight\Auth** (SQLite).
Pages live as `.txt` files on disk; SQLite is used only for authentication.

## Running locally

```bash
composer install
php -S localhost:8080 -t public public/index.php
```

First user:

```bash
php bin/console user:create admin admin@example.com admin
```

## Key directories

| Path | Purpose |
|---|---|
| `src/` | PHP source (namespace `Station0\`) |
| `config/` | `app.php` (env config), `roles.php` |
| `content/pages/` | Flat-file pages (`page.txt` per dir) |
| `templates/` | Twig templates + block definitions |
| `writable/` | Cache, sessions, logs, `db.sqlite` |
| `public/` | Entry point + static assets |
| `bin/console` | CLI tool |

## Architecture

**Request flow:** `public/index.php` → `Bootstrap::create()` → Slim app with PHP-DI container → middleware stack → controller → Twig response.

**DI container** is built in `src/Bootstrap.php`. All services and controllers are registered there.

**Content** is stored in `content/pages/{slug}/page.txt` with Kirby-style front matter:

```
Title: Page title
Published: true
---
Markdown body (or YAML block list)
```

URL mapping: `content/pages/about/team/page.txt` → `/about/team`.

**Block system:** Page body can be a YAML list of blocks. Each block type lives in `templates/blocks/{type}/` with a `schema.yaml` (field definitions for admin UI) and `template.twig`.

**Caching:** `FileCache` (SHA-1 keyed `.cache` files in `writable/cache/`). Invalidated on page save or `php bin/console cache:clear`. Twig compiled templates go to `writable/cache/twig/` (disabled in debug mode).

## PHP namespace & package

- Namespace: `Station0\`
- Composer package: `lexislav/station0`
- PHP ≥ 8.2 required

## Middleware stack (admin routes)

1. `RoutingMiddleware`
2. `TwigMiddleware`
3. `Guard` (CSRF)
4. `ErrorMiddleware`
5. `AuthMiddleware` — redirects to `/admin/login` if not logged in
6. `RoleMiddleware` — role check (`admin` / `editor`)

## CLI commands

```bash
php bin/console user:create <username> <email> [role]
php bin/console user:reset-password <email>
php bin/console cache:clear
php bin/console help
```

## Key classes

| Class | File | Purpose |
|---|---|---|
| `Bootstrap` | `src/Bootstrap.php` | DI setup, routing, middleware |
| `ContentRepository` | `src/Service/ContentRepository.php` | Flat-file CRUD, front-matter parsing |
| `Page` | `src/Service/Page.php` | Page entity |
| `PageRenderer` | `src/Service/PageRenderer.php` | Markdown + block rendering, cache |
| `BlockRegistry` | `src/Service/BlockRegistry.php` | Block schema/template loading |
| `UserRepository` | `src/Service/UserRepository.php` | Wrapper around Delight\Auth |
| `FileCache` | `src/Service/FileCache.php` | File-based cache (get/set/flush) |
| `Slug` | `src/Support/Slug.php` | URL slug generation |

## Tests

PHPUnit is in `require-dev` but no test suite exists yet. To add tests, create a `tests/` directory and configure `phpunit.xml`.

## Environment variables

```
BASE_URL=http://localhost:8080
DEBUG=true
ADMIN_PATH=/admin
HTTPS=false
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
- CSRF token is required on all `POST` forms — include `{{ csrf() }}` in every form.
- `Published: false` pages are hidden on the public site but visible in the admin.
- Block schemas define the admin form fields; adding a new block type = new dir under `templates/blocks/`.

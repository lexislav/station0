# Station0

Lightweight flat-file CMS built on PHP 8.2+. Pages are stored as plain text files on disk — no database migrations, fully Git-friendly. SQLite is used only for authentication.

## Stack

- **Framework**: Slim 4
- **Templates**: Twig 3
- **Markdown**: League/CommonMark (GFM + front matter)
- **Auth**: Delight\Auth (SQLite)
- **DI**: PHP-DI 7

## Requirements

- PHP ≥ 8.2 (with `pdo_sqlite` extension)
- Composer

## Installation

```bash
composer create-project lexislav/station0-skeleton mysite
cd mysite
php -S localhost:8080 -t public public/index.php
```

On first visit to `http://localhost:8080/admin`, a setup form lets you create the first admin account in the browser.

## This package

`lexislav/station0` is the **core library** — it contains the PHP classes, admin templates, and CLI binary. It is installed into `vendor/` by the skeleton project.

For a ready-to-use project skeleton, see [lexislav/station0-skeleton](https://github.com/lexislav/station0-skeleton).

## CLI (via skeleton)

```bash
php vendor/bin/console user:create <username> <email> [role]
php vendor/bin/console user:reset-password <email>
php vendor/bin/console cache:clear
```

## Package structure

```
admin/templates/   Admin Twig templates
bin/console        CLI binary (exposed via composer bin)
config/roles.php   Role definitions
src/               PHP source (namespace Station0\)
```

## License

MIT

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
git clone <repo> station0
cd station0
composer install
```

Copy `.env.example` to `.env` and adjust:

```env
APP_BASE_URL=http://localhost:8080
APP_DEBUG=true
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=user@example.com
MAIL_PASSWORD=secret
MAIL_FROM=noreply@example.com
MAIL_FROM_NAME=Station0
```

Start the built-in PHP server:

```bash
php -S localhost:8080 -t public public/index.php
```

Create the first admin user:

```bash
php bin/console user:create admin admin@example.com admin
```

Open `http://localhost:8080/admin` and log in.

## Content structure

Pages live in `content/pages/` as `page.txt` files:

```
content/pages/
├── page.txt          → /
├── about/
│   └── page.txt      → /about
└── about/team/
    └── page.txt      → /about/team
```

Each file starts with front matter followed by a Markdown body:

```
Title: My Page
Published: true
---

# Hello world

Regular **Markdown** content.
```

### Block-based content

The body can also be a YAML list of typed blocks:

```yaml
- type: text
  body: |
    # Heading
    Paragraph text.

- type: gallery
  columns: 3
  images:
    - src: /uploads/photo.jpg
      alt: A photo
```

Block types are defined in `templates/blocks/{type}/` — add a `schema.yaml` and a `template.twig` to create a new block.

## Templating

Templates live in `site/templates/` and use [Twig 3](https://twig.symfony.com/).

### Page templates

Each page renders through a Twig template. The template is selected via the `Template` front-matter field (defaults to `page`):

```
Title: My Page
Template: landing
---
...
```

This renders `site/templates/landing.twig`. Create as many templates as you need.

Every page template receives these variables:

| Variable | Type | Description |
|---|---|---|
| `page.title` | string | Page title |
| `page.metatitle` | string\|null | SEO title (falls back to `page.title`) |
| `page.slug` | string | Last URL segment |
| `page.urlPath` | string | Full URL path, e.g. `/about/team` |
| `page.published` | bool | Published state |
| `page.template` | string | Template name |
| `page.extra` | array | Any custom front-matter fields |
| `content` | string (HTML) | Rendered page body |
| `app.name` | string | Site name from config |
| `app.baseUrl` | string | Base URL from `.env` |

### Layout inheritance

The default layout is `layout.twig`. Override blocks as needed:

```twig
{# site/templates/page.twig #}
{% extends 'layout.twig' %}

{% block title %}{{ page.metatitle ?? page.title }} · {{ app.name }}{% endblock %}

{% block body %}
<article>
    <h1>{{ page.title }}</h1>
    <div class="content">{{ content|raw }}</div>
</article>
{% endblock %}
```

Available blocks: `title`, `head` (inside `<head>`), `body`.

### Block templates

Each content block type has its own Twig template at `site/templates/blocks/{type}/template.twig`. The block's YAML data is available as `block`:

```twig
{# site/templates/blocks/gallery/template.twig #}
<div class="gallery gallery--cols-{{ block.columns|default(3) }}">
    {% for image in block.images|default([]) %}
        <img src="{{ image.src }}" alt="{{ image.alt|default('') }}">
    {% endfor %}
</div>
```

To add a new block type, create a directory under `site/templates/blocks/{type}/` with:
- `schema.yaml` — field definitions for the admin editor
- `template.twig` — the Twig template

## Admin

| URL | Description |
|---|---|
| `/admin` | Dashboard |
| `/admin/pages` | Page list |
| `/admin/pages/new` | Create page |
| `/admin/users` | User management (admin role only) |

## Roles

- **admin** — full access including user management
- **editor** — create and edit pages, no user management

## CLI

```bash
php bin/console user:create <username> <email> [role]
php bin/console user:reset-password <email>
php bin/console cache:clear
php bin/console help
```

## Caching

Rendered pages are cached in `writable/cache/`. Cache is invalidated automatically on page save. Clear manually:

```bash
php bin/console cache:clear
```

## Project structure

```
bin/            CLI console
config/         App config and roles
content/pages/  Flat-file content
public/         Web root (index.php, assets)
src/            PHP source (namespace Station0\)
templates/      Twig templates and block definitions
writable/       Cache, sessions, logs, db.sqlite
```

## License

MIT

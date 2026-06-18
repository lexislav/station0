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
composer create-project lexislav/get-station0 mysite
cd mysite
php -S localhost:8080 -t public public/index.php
```

On first visit to `http://localhost:8080/admin`, a setup form lets you create the first admin account in the browser.

## Apache deployment

If you're serving via Apache (Homebrew `httpd`, XAMPP, system Apache, …):

1. Point your vhost `DocumentRoot` at the project's `public/` directory.
2. Allow `.htaccess` overrides for that directory and ensure `mod_rewrite` is loaded.

Example vhost (Homebrew `httpd` at `/opt/homebrew/etc/httpd/extra/httpd-vhosts.conf`):

```apache
<VirtualHost *:80>
    ServerName mysite.local
    DocumentRoot "/path/to/mysite/public"

    <Directory "/path/to/mysite/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

In `httpd.conf` make sure this line is uncommented:

```
LoadModule rewrite_module lib/httpd/modules/mod_rewrite.so
```

Restart: `brew services restart httpd`.

**Symptom of misconfiguration:** Apache's default "Not Found — The requested URL
was not found on this server." page on `/admin` (or any sub-path) means the
rewrite isn't being applied — check `AllowOverride` and `mod_rewrite`.

## This package

`lexislav/station0` is the **core library** — it contains the PHP classes, admin templates, and CLI binary. It is installed into `vendor/` by the skeleton project.

For a ready-to-use project skeleton, see [lexislav/get-station0](https://github.com/lexislav/get-station0).

## Media & uploads

Uploaded assets are stored **next to the page** they belong to:

```
site/content/pages/about/
  page.txt
  team-photo.jpg          ← uploaded asset
```

Filenames are slugified on upload (`Team Photo.JPG` → `team-photo.jpg`) and
collisions resolve as `team-photo-2.jpg`, `-3`, … Allowed types: jpg, png,
gif, webp, svg (max 8 MB).

In stored content, asset references are **page-relative** — bare filenames:

```yaml
- type: gallery
  images:
    - src: team-photo.jpg
      alt: The team
```

At render time, the bare filename is resolved against the current page
(`/media/about/team-photo.jpg`). Move or rename the page directory and the
references still work — they were never absolute.

To migrate any older absolute `/media/{path}/file.ext` references in stored
pages to the new bare-filename form:

```bash
php vendor/bin/console assets:relink           # apply
php vendor/bin/console assets:relink --dry-run # preview
```

Public asset URLs follow the page URL: `/media/{page-path}/{filename}`.
The root page uses `/media/~/{filename}`.

## Per-template block restrictions

A template can restrict which block types its pages may use, and pre-seed a new
page with starter blocks. Drop an optional manifest beside the template file:

```
site/templates/gallery.twig         ← the template
site/templates/gallery.blocks.yaml  ← its block manifest (optional)
```

```yaml
# site/templates/gallery.blocks.yaml
allowedBlocks:        # the "+ Add block" palette shows only these, in order
  - text
  - gallery
defaultBlocks:        # a NEW page of this template opens with these inserted
  - gallery
```

Both keys are optional and compose:

- **`allowedBlocks`** — restricts the editor palette. No declaration ⇒ all
  blocks are available (the default).
- **`defaultBlocks`** — pre-inserts blocks into **new** pages only, at their
  schema default field values. No declaration ⇒ a single empty `text` block.
  Pre-seeded blocks must be a subset of the allow-list.

Unknown block names are ignored, and an allow-list that filters down to nothing
falls back to the full palette. This mirrors how a page's `AllowedChildTemplates`
front-matter field restricts which child *templates* a page accepts — here it is
the block types inside a page that are restricted, keyed by template.

## CLI (via skeleton)

```bash
php vendor/bin/console user:create <username> <email> [role]
php vendor/bin/console user:reset-password <email>
php vendor/bin/console cache:clear
php vendor/bin/console assets:relink [--dry-run]
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

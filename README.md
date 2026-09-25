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

### Thumbnails

Resize images in templates with the `thumb` filter. It takes the resolved
`/media/...` URL and returns a URL to a smaller copy; the copy is generated with
GD on first request and cached in `writable/cache/thumbs/`.

```twig
<img src="{{ image.src|thumb(600) }}"                         {# max width 600 px #}
     srcset="{{ image.src|thumb_srcset([400, 800, 1200]) }}"
     sizes="(min-width: 900px) 33vw, 100vw" loading="lazy" alt="">
{{ hero|thumb(0, 400) }}                                       {# max height 400 px #}
{{ photo|thumb(300, 300) }}                                    {# fit inside the box #}
{{ photo|thumb(300, 300, 'cover') }}                           {# crop to fill the box #}
{{ photo|thumb(600, format='webp') }}                          {# convert to WebP #}
```

- Images are never upscaled. External URLs, SVG, GIF, documents, missing files
  and sizes that are not smaller than the original come back unchanged, so the
  filter is safe to apply everywhere. Without `ext-gd` it returns the original.
- JPEG, PNG (with transparency) and WebP keep their format. EXIF rotation of
  phone photos is applied (needs `ext-exif`); EXIF metadata such as GPS is
  dropped from the thumbnail.
- URLs are signed (`/thumb/600x0/{signature}/{page-path}/{file}`) so nobody can
  request arbitrary sizes. The signing key is created in `writable/thumbs.key`
  (override with `'thumbs' => ['secret' => ...]` in `site/config.php`).
  Replacing the source image changes the URL.
- Markdown images in text blocks are thumbnailed automatically: `src` is capped
  at 1200 px with a 2x candidate in `srcset`, plus `loading="lazy"`.
- The admin editor shows 160 px previews instead of the originals.

Optional settings in `site/config.php`:

```php
'thumbs' => [
    'format'   => 'webp', // convert all thumbnails to WebP ('original' per call keeps the format)
    'markdown' => 1200,   // max width of markdown images; 0 = leave them alone
    'static'   => true,   // write thumbnails to public/thumb/ so the web server serves them
    'secret'   => '…',    // signing key; default: generated into writable/thumbs.key
],
```

With `static`, each thumbnail is written to the path of its own URL under
`public/thumb/`, so after the first request the web server sends the file
without starting PHP (the stock `.htaccess`, nginx `try_files $uri …` and
`php -S … public/index.php` all serve existing files first). A copy stays
reachable after its source image is deleted until `thumbs:clear`.

```bash
php vendor/bin/console thumbs:warm   # pre-generate thumbnails of all published pages (server must run)
php vendor/bin/console thumbs:clear  # delete generated thumbnails
```

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

## Select options from a collection

A `select` field — in a block schema, in a list item, or in a collection
schema — can take its options from a collection instead of a static list:

```yaml
# site/templates/blocks/route/schema.yaml
label: Route
fields:
  from:
    type: select
    label: From
    options_from: collection:points   # one option per published item
    group_by: river                   # optional: item field → <optgroup>
    sort_by: -km                      # optional: item field, "-" = descending
    option_label: "{title} (km {km})" # optional: {title}, {slug}, {<field>}
    placeholder: "— choose a point —" # optional, defaults to "—"
```

The stored value is the item **slug**; resolve it in the block template with
`collection_item('points', block.from)`. Numeric sort values accept a decimal
comma (`318,5`); items without the field sort last. If a stored slug no longer
exists, the editor keeps it as a "(not found)" option instead of dropping it.

### Letting the editor pick the collection

`options_from: collections` offers the items of **every** collection (or
`collections:banners,shared-blocks` for just those, in that order), one
`<optgroup>` per collection. The stored value is `<collection>/<slug>`:

```twig
{% set item = collection_item(block.ref) %}   {# one-arg form: "banners/summer-sale" #}
{% if item %}{{ render_collection_item(item) }}{% endif %}
```

`{collection}` is available in `option_label` / `sort_by` next to the item fields.

## Select options from pages

Relations between pages ("article → related article") use `options_from: pages`:

```yaml
related:
  type: list
  label: Related articles
  item:
    page:
      type: select
      label: Article
      options_from: pages:/blog         # descendants of /blog; plain "pages" = all
      template: article                 # optional: string or list of templates
      sort_by: -date
      option_label: "{title} ({path})"
```

Only published pages are offered. The stored value is the page's URL path;
`page(value)` returns the page (or `null` if it is missing or not published):

```twig
{% for rel in fields.related %}
  {% set p = page(rel.page) %}
  {% if p %}<a href="{{ p.urlPath }}">{{ p.title }}</a> {{ page_fields(p).subtitle }}{% endif %}
{% endfor %}
```

Label/sort/group fields: `title`, `slug`, `path`, `template`, `sort`, `date`,
`parent`, `parent_title`, plus the page's own fields. Renaming or moving a page
does not rewrite references to it — the editor then shows the old path as
"(not found)".

Static `options` also accept richer forms now — `{value: label}` maps and
`[{value, label, group}]` entries — next to the plain string list.

## Collection groups (admin menu tabs)

Collections can be grouped into their own tab in the admin menu. The simple way
is one key in the collection's `_collection.yaml`:

```yaml
# site/content/collections/products/_collection.yaml
label: Products
group: Shop
```

Every collection with `group: Shop` moves out of the generic **Collections** tab
into a **Shop** tab. A group with several collections opens a list of them; a
group with a single collection links straight to its items.

When a group needs more settings, declare it centrally (optional):

```yaml
# site/content/collections/_groups.yaml
shop:                 # id — `group: shop` and `group: Shop` both match
  label: E-shop       # overrides the inline label
  icon: "🛒"          # text/emoji, or inline <svg …> markup
  roles: [editor]     # who sees the tab; admins always do; omit = everyone
```

Tabs follow the order of `_groups.yaml`, then inline-only groups alphabetically.
A central group without any collection produces no tab. `roles` is enforced
server-side too: the collection's list, forms, saves and uploads return 403
for users without the role.

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

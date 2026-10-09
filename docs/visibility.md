# Page visibility

Station0 (≥ 0.9) decides who sees a page along two independent axes:

- **Publication.** Is the URL reachable? Set by `Status`, `PublishAt`, `ExpireAt` and the parent pages.
- **Listing.** Where does a live page show up? Set by `Listing`.

## Front matter

```
Title: Summer exhibition
Status: published          # draft | published | archived
PublishAt: 2026-06-01 09:00
ExpireAt: 2026-09-30 23:59
PublishedAt: 2026-06-01    # date shown to readers / used for sorting
Listing: nav-hidden        # listed | nav-hidden | unlisted
Cascade: false             # when not live, hide only this page, not its subpages
---
```

All keys are optional. Keys are case-insensitive.

| Key | Values | Default |
|---|---|---|
| `Status` | `draft`, `published`, `archived` | taken from `Published` (`true` → published, `false` → draft) |
| `PublishAt` | `Y-m-d H:i` | — |
| `ExpireAt` | `Y-m-d H:i` | — |
| `PublishedAt` | `Y-m-d H:i` or `Y-m-d` | — |
| `Listing` | `listed`, `nav-hidden`, `unlisted` | `listed` |
| `Cascade` | `true`, `false` | `true` |
| `Published` | `true`, `false` | legacy, see [Migration](#migration) |

## Publication

A page's **own state** is computed on every request:

| Status | PublishAt | ExpireAt | State | Public response |
|---|---|---|---|---|
| `draft` | ignored | ignored | draft | 404 |
| `archived` | ignored | ignored | archived | **410 Gone** |
| `published` | in the future | | scheduled | 404 |
| `published` | none or past | none or in the future | **live** | 200 |
| `published` | none or past | past | expired | **410 Gone** |

- A draft stays a draft whatever its dates say. Scheduling means "published, with a future `PublishAt`".
- **410 Gone** tells search engines that the page was removed on purpose. If the site has
  `site/templates/410.twig`, that template renders the response; otherwise `404.twig` renders it with status 410.
  Both get `status` and `urlPath`.

### Parent pages (inheritance)

A page is **live** only if it is live itself *and* every ancestor is live.

- If a page is live but an ancestor isn't, its state is `hidden`. The admin tree shows it as "hidden by parent".
  The response follows the ancestor's state: archived or expired gives 410, anything else gives 404.
- **`Cascade: false`** on a parent hides only that page. Its subpages stay live and `child_pages()` still lists them.
  Example: you take a blog's index page down while the posts stay reachable. A cascading ancestor further up still
  hides the whole subtree.
- The **homepage is not an ancestor** here. A draft homepage never takes the site down.

### Dates and timezone

Dates are stored as `Y-m-d H:i` in the **site timezone**:

```php
// site/config.php
'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Prague',
```

Without it, PHP's default timezone applies. If you change the timezone later, existing schedules shift with it,
because the stored dates carry no offset. Values with an explicit offset (`2026-06-01 09:00+02:00`) are accepted too.

### Caching

Visibility is checked on every request, before any cache is used. Rendered page bodies are cached, though, and a block
template that calls `child_pages()` or `collection()` bakes *other* entries into that HTML.
`VisibilityHorizon` keeps the time of the next scheduled change (the earliest future `PublishAt` / `ExpireAt` across
all pages and collection items) as a cache entry. When that time arrives, it flushes the cache.
Every admin save flushes the cache as before. If a reverse proxy or CDN sits in front of the site, keep its TTL
shorter than your scheduling precision.

## Listing

| Listing | URL | `child_pages()` | `nav_pages()` / `top_level_pages()` | `page(path)` |
|---|---|---|---|---|
| `listed` | ✓ | ✓ | ✓ | ✓ |
| `nav-hidden` | ✓ | ✓ | – | ✓ |
| `unlisted` | ✓ | only with `child_pages(path, true)` | – | ✓ |

`Listing` only affects live pages and is not inherited. `child_pages('/campaign')` lists the children of an unlisted
`/campaign`, because asking for them by path is deliberate.

## Twig

```twig
{# menus #}
{% for p in top_level_pages() %}…{% endfor %}       {# = nav_pages('/') #}
{% for p in nav_pages('/services') %}…{% endfor %}   {# submenu #}

{# content listings #}
{% for p in child_pages(page.urlPath) %}…{% endfor %}
{% for p in child_pages(page.urlPath, true) %}…{% endfor %}   {# incl. unlisted #}

{# page properties #}
{{ page.status }}      {# draft | published | archived #}
{{ page.state }}       {# live | scheduled | expired | draft | archived | hidden #}
{{ page.isLive }}  {{ page.inNav }}  {{ page.isListed }}  {{ page.listing }}
{{ page.publishAt }}  {{ page.expireAt }}  {{ page.publishedAt }}
{% if preview %}…{% endif %}   {# true while an editor previews a non-live page #}
```

`page.published` still works and is true when `status` is `published`.

## Preview for editors

A signed-in admin or editor who opens a non-live page sees it with a "not public" bar at the top. The bar shows the
state, the schedule or the hiding ancestor, and an edit link. The response is sent with
`Cache-Control: private, no-store` and `X-Robots-Tag: noindex`. Listings inside the preview stay public: only the
page itself is previewed. Everyone else gets the 404 / 410.

## Members-only pages

Visibility decides whether a page is public **at all**; `Access:` decides **who** may see a live page. With
`Access: members` (or `access.mode: members` in `site/config.php`) a live page is shown only to signed-in visitors.
For everyone else it redirects to `access.redirect`, it disappears from `top_level_pages()`, `nav_pages()`,
`child_pages()` and `page()` (pass `includeGated=true` to keep it in a teaser list), and its media answer 403.
Preview of non-live pages stays limited to admins and editors; members never see drafts. See the README section
"Members-only access".

## Collections

Collection items use the same `Status`, `PublishAt` and `ExpireAt`. They have no listing, no inheritance and no
404 / 410, because items have no URLs. `collection('name')` and `collection_item(…)` return **live items only**.

If a collection's schema declares its own field named `status`, `publishAt` or `expireAt`, that field keeps its
meaning. That collection's visibility then falls back to `Published: true|false`.

## Migration

You don't need to change anything. Existing files behave exactly as before:

- `Published: true|false` becomes `Status: published|draft`.
- A future `PublishedAt` still schedules the page **as long as `PublishAt` is absent**. When you open such a page in
  the admin, the form pre-fills "Publish at" from it. The next save writes `PublishAt`, and from then on
  `PublishedAt` is only the date shown to readers.

When it saves, the admin writes `Status:` and also a matching `Published:` line (true only for `published`), so
an older station0 never exposes a draft or archived page. If you edit files by hand and both keys are present,
`Status` wins.

`status`, `publishAt`, `expireAt`, `listing`, `cascade` and `access` are now reserved and can't be used as page-field names.

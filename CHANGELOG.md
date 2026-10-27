# Changelog

All notable changes to `lexislav/station0` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.10.2] - 2026-10-27

### Added
- **Redirects: a 404 log you can work through.** The log at
  `/admin/redirects/404` now has:
  - **Filters:** path contains, referrer contains (`-` = no referrer),
    minimum hits, seen in the last 24 h / 7 / 30 / 90 days.
  - **Sortable columns** (path, hits, first seen, last seen) and paging
    (100 per page). It also shows the first-seen date.
  - **A suggested target** per path: a live page with the same last segment
    (`/old/hello-world` → `/blog/hello-world`, `.html` stripped), else a page
    a few typos away, else the nearest live parent. It never suggests the
    homepage. **Redirect** creates the 301 in one click; **Create redirect**
    opens the form with both fields filled. Either way you return to the log
    with your filters kept.
  - **Bulk ignore / show again** for the checked rows.
  - **Resolved paths are dropped.** Opening the log removes the paths that a
    rule or a live page now answers, wherever the rule came from (admin,
    YAML, console, automatic).

## [0.10.1] - 2026-10-26

### Added
- **Redirect manager**, the first bundled plugin. Turn it on with
  `'plugins' => ['redirects' => []]`.
  - **Automatic redirects.** Renaming a page's slug or moving it in the
    Structure tree adds one 301 rule for the whole subtree
    (`/old/{path*}` → `/new/{path}`). Its media files follow too. Chains are
    flattened, and renaming a page back removes the rule.
  - **Rules** in `site/content/_redirects.yaml`: exact paths, patterns
    (`{name}` = one segment, `{name*}` = several segments), anchored regexes
    with `$1`, `410 Gone`, and `force` rules that apply even over an
    existing page. Options per rule: case-insensitive matching, dropping the
    query string, and hit counting.
  - **Cost.** Rules are consulted only on the 404/410 path. They are compiled
    to `writable/cache/redirects.php`: exact rules are a hash lookup, and all
    patterns share one `(*MARK)` regex. The force middleware exists only
    while force rules do.
  - **Validation.** Captures never reach the host of a target, so the rules
    can't be used as an open redirect. Sources under the admin path,
    `/thumb`, the member sign-in path or a site action are rejected, and so
    are bad regexes and redirect loops.
  - **404 log** (`station0_404`). It ignores noise globs, stores paths
    without their query string, and keeps a capped number of rows.
  - **Admin.** A Redirects tab with the rule list, a form, "Test a URL" and
    the 404 log with "Create redirect". Each page's edit form gets a
    Redirects panel listing the old URLs that lead to it, with "Add old
    URL". The Structure tree shows a badge with the number of redirects.
    Editors manage exact rules; patterns, regexes and force rules need an
    admin.
  - **Console:** `redirects:list`, `redirects:test`, `redirects:add`,
    `redirects:import` (CSV), `redirects:compile` and `redirects:404`.
  - See `docs/redirects.md`.

## [0.10.0] - 2026-10-26

### Added
- **Computed collection titles.** `_collection.yaml` can build an item's
  title from its fields: `title: "{name} {surname}"`, or
  `title: {from: "{brand} – {model}", input: editable}`. Literal text
  between placeholders is kept, and separators left by an empty field are
  cleaned up. The title input is `readonly` (the default: filled
  automatically), `hidden`, or `editable`. In editable mode a typed title is
  kept as an override; the item follows the fields again once the title is
  cleared or the "Use built title" button is used. The title is stored in
  `Title:` on save, also for items saved by tasks, so templates, lists and
  hooks are unchanged. New console command `collections:retitle` updates
  existing items. Select `option_label` uses the same placeholder rules.
- **Plugins (experimental).** Optional features can be enabled per site in
  `site/config.php` under `'plugins' => [...]`. A short name loads a plugin
  bundled with station0 (`src/Plugin/<Name>`), and a class name loads one
  from a Composer package. A plugin that is not listed is never loaded, so
  a site without plugins runs as before. A plugin implements
  `Station0\Plugin\Plugin` (`name()`, `register(Registrar)`) and can declare
  services, public and admin routes, middleware that is added only when it
  is needed, content event listeners, 404/410 resolvers, Twig functions,
  filters, globals and templates, admin menu tabs, console commands, SQLite
  migrations (`station0_migrations`) and admin translations. It can also add
  **panels** to the page and collection-item edit forms and **badges** to
  their list rows. A plugin that fails to load is logged and skipped, and the
  site keeps running. New console command `plugins:list`. See
  `docs/plugins.md`.
- **Content events dispatcher** `Station0\Support\Events`. The admin's
  `page.*` and `collection.item.*` events now go through it. Site task hooks
  (`TaskHooks`) are one listener of it and plugins can be others. Event names,
  payloads and the hook behavior are unchanged.

### Changed
- The admin `PageController` and `CollectionController` take an
  `?Events` dispatcher where they took `?TaskHooks`.
- `RoleMiddleware` always lets the `admin` role through, so a route that
  needs `editor` also works for admins. Core routes only check `admin`, so
  they behave as before.

### Security
- **Admin pages could be framed by another site (clickjacking).** Every
  response under the admin path, including login, setup, error pages and
  admin assets, now sends `X-Frame-Options: DENY`,
  `Content-Security-Policy: frame-ancestors 'none'`,
  `Referrer-Policy: same-origin` and `X-Content-Type-Options: nosniff`
  (`SecurityHeadersMiddleware`). Public routes are unchanged. They stay up
  to the site or the web server.

### Fixed
- **A crafted POST could give a page a child-only template.** Templates
  listed only in some page's `AllowedChildTemplates` are hidden from the
  template select, but the server still accepted them anywhere. Saving a
  page now keeps such a template only under a parent that lists it; elsewhere
  it falls back (`page` for a new page, the current template on edit), as
  for an unknown template. Correctly placed child pages save as before.
  New `ContentRepository::templateAllowedUnder()`.

## [0.9.3] - 2026-10-22

### Fixed
- **A `.txt` upload could replace a page's content.** The content file was
  the first `*.txt` in the page folder, so an uploaded `a.txt` beside
  `page.txt` became the page: it rendered the upload, switched template, and
  the real `page.txt` (front matter included) could be downloaded through
  `/media`. Pages, the homepage included, now reject `.txt` uploads (use
  `.md` or `.pdf`); collection items still accept them. When a page folder
  already holds several `.txt` files, the one named after a template wins,
  then `page.txt`, and `app.log` gets a warning naming the folder.
  `ContentRepository` takes the templates dir and a logger as optional
  constructor arguments for this.

## [0.9.2] - 2026-10-22

### Changed
- The required PHP version is now `^8.4.1` (was `^8.2`). Nothing new is
  needed at runtime: `symfony/yaml` 8 already required PHP 8.4.1, so
  installing on 8.2 or 8.3 already failed. It failed while Composer resolved
  dependencies; now station0 reports the requirement itself. README and
  CLAUDE.md say the same.

## [0.9.1] - 2026-10-22

### Security
- **Uploads could keep a disallowed extension.** A document upload was
  accepted when *either* its extension or its sniffed MIME type was on the
  allow-list, and the client extension was kept on disk — so a `%PDF-` or
  plain-text file named `shell.php` was stored as `shell.php` in the page
  (or collection item) folder. Only admins / editors can upload, but on hosts
  that serve the project root it was code execution. The extension must now
  be on the document allow-list (`MediaService::DOCUMENT_EXT`).
- **`..` in page paths escaped the pages tree.** `ContentRepository::find()`
  used URL segments as directory names, and Slim URL-decodes `%2e%2e`, so
  `GET /%2e%2e` could render a `.txt` file above `site/content/pages/`, and
  `POST /admin/pages/%2e%2e/delete` could delete a file there together with
  every other file in its folder. `find()` now rejects `.`, `..`, backslashes
  and control bytes (which also turns a NUL byte from a 500 into a 404).
- Dependency floors raised past published advisories: `twig/twig` ^3.27
  (code injection via `{% use %}` / `_self` macro names, sandbox bypasses),
  `league/commonmark` ^2.10.4 (GFM table DoS and other parser DoS),
  `symfony/yaml` ^8.1.6 (recursion / alias-expansion crashes),
  `slim/slim` ^4.15.3 (reflected XSS in `HtmlErrorRenderer`).
  Sites should run `composer update` to pick them up.

## [0.9.0] - 2026-10-09

### Added
- **Page visibility model** (see `docs/visibility.md`). Two independent axes:
  - Publication: `Status: draft | published | archived`, `PublishAt` (published
    page is scheduled until then), `ExpireAt` (published page expires then).
    Archived and expired pages answer **410 Gone** (`410.twig` when the site
    has one, else `404.twig` with status 410); draft / scheduled stay 404.
  - Listing: `Listing: listed | nav-hidden | unlisted`. Nav-hidden pages
    stay in `child_pages()` but leave menus; unlisted pages are reachable
    by URL (and `page()`) only.
- **Parent inheritance.** A page is live only when all its ancestors are;
  the homepage is not an ancestor. `Cascade: false` on a parent hides only
  the parent itself (e.g. a blog index taken down while posts stay live).
- `nav_pages(path = '/')` Twig function for menus and submenus;
  `child_pages(path, includeUnlisted = false)` gained its second argument.
- Twig: `page.status`, `page.state`, `page.isLive`, `page.inNav`,
  `page.isListed`, `page.listing`, `page.publishAt`, `page.expireAt`.
- **Preview for editors.** A signed-in admin / editor sees non-live pages
  with a "not public" bar (private, no-store, noindex); templates get a
  `preview` flag.
- **Time-aware cache.** `VisibilityHorizon` flushes cached HTML when the
  next PublishAt / ExpireAt passes, so listings rendered inside blocks
  never lag behind a schedule.
- Site `timezone` config (`site/config.php`), used for all front-matter
  datetimes; missing = PHP default.
- Collection items: `Status`, `PublishAt`, `ExpireAt`.
- Admin: Visibility fieldset (status, publish / expire at, listing, display
  date, cascade) for pages, status + schedule for collection items;
  validation that ExpireAt is after PublishAt; state badges in the page
  tree, stream records and item lists (published / scheduled / expired /
  archived / draft / hidden by parent, not in menu / unlisted); en + cs.
- `page.saved` / `page.deleted` hook payloads carry `status`.
- **Members-only access.** New `access` section in `site/config.php`:
  `mode: members` gates every public page (anonymous visitors are redirected
  to `access.redirect`) and — unless `media: false` — `/media` + `/thumb`
  (403). `public` lists always-public paths (`*` = prefix). A page can
  override it with `Access: public|members` in its front matter, inherited by
  its sub-pages — also to gate just a section of a public site. Media follow
  their page. For anonymous visitors `top_level_pages()`, `nav_pages()`,
  `child_pages()` and `page()` leave out members-only pages
  (`includeGated=true` keeps them for teasers). Gated responses are sent
  `private` (never cached publicly). With `thumbs.static`, thumbnails of
  members-only media are not written to `public/thumb/` — neither by the app
  nor by `console thumbs:warm` — but served through PHP (run `thumbs:clear`
  after gating a section that already had static thumbnails). Default `mode: public` changes nothing.
- **Visitors and passes.** `Visitor` service + `visitor` Twig global
  (`authenticated`, `guest`, `member`, `staff`, `label`, `email`,
  `expiresAt`, `meta`…). A guest pass (`grantPass()`) lets a browser in for a
  fixed time without an account; a member sign-in (`signInMember()`) lasts
  for the browser session or, remembered, `access.rememberDays` (sliding).
  Tokens live in the `station0_passes` table (hashed); an admin/editor signed
  into the admin counts as a member on the site.
- **`member` role** and **public member sign-in** at `access.loginPath`:
  e-mail + password (+ remember), one-time sign-in links by e-mail
  (`/link`), set a new password (`/password`), sign out (`/logout`). The page
  is the site's `login.twig` (fallback: a minimal built-in page); the e-mail
  can be customised with `emails/login-link.twig`.
- **Site actions** (`site/actions/*.php`): the site's own request handlers
  (form posts, small endpoints) on static paths, CSRF-protected, optionally
  members-only. The handler gets an `ActionContext` (input, session-backed
  state + flash, visitor, members, rate limiter, collections, mail, redirect /
  render / json). Templates read the state with `action_state('<name>')`.
- `RateLimiter` (fixed window + lockout, table `station0_throttle`), used by
  the member sign-in and available to actions.
- Users list: filter by role.

### Changed
- `PublishedAt` is the date shown to readers. It still schedules a page
  while `PublishAt` is absent (unchanged behavior for existing content);
  the admin moves such a schedule into `PublishAt` on the next save.
- Saving writes `Status:` plus a `Published:` mirror (true only for
  `published`), so older versions never expose drafts / archived pages.
- `top_level_pages()` skips nav-hidden and unlisted pages; `child_pages()`
  skips unlisted ones.
- `collection_item()` returns live items only, like `collection()`
  (it used to return drafts too).
- Stream counters in the admin count non-live records, not just drafts.
- `Page::$published` / `CollectionItem::$published` became `$status`;
  reading or writing `->published` (PHP and Twig) still works.
- The admin is limited to `admin.roles` (default `admin`, `editor`): other
  accounts (members) are refused at the admin login and get 403 inside.
  Previewing non-live pages is limited to the same roles.

### Notes
- `status`, `publishAt`, `expireAt`, `listing`, `cascade` and `access` are
  reserved page-field names now. A collection schema field with one of the first
  three names keeps working; that collection's visibility then uses
  `Published`.

## [0.8.2] - 2026-09-27

### Added
- **Page groups.** `Group: Downloads` in a page's front matter gives that page
  and its subtree an own admin menu tab, like `group:` does for collections.
  The tab shows the subtree (drag & drop as in Structure; streams with all
  their records); the pages also stay in the Structure tree, marked with a
  group badge. A page belongs to its nearest grouped ancestor-or-self. Page
  forms keep the group's tab highlighted and "Cancel" / delete return to it.
- Pages and collections share group ids, so one tab can hold both (e.g. the
  `/shop` subtree and the `products` collection).

### Changed
- Central group settings moved to `site/content/_groups.yaml`; the old
  `site/content/collections/_groups.yaml` is still read (the new file wins
  per group id).
- Group tabs live at `/admin/groups/{id}`; `/admin/collection-groups/{id}`
  redirects there.
- The Streams tab lists only ungrouped streams (grouped ones live in their
  group's tab) and disappears when none are left.
- `CollectionGroups` is now `NavGroups` (`groupOf()` → `groupOfCollection()`,
  new `groupOfPage()`); the `collection_groups()` Twig function is now
  `nav_groups()`.
- `group` is a reserved page-field name (it is the page's menu group). A page
  field named `group` in a template manifest is now dropped.

## [0.8.1] - 2026-09-25

### Fixed
- `bin/console` crashed on a fresh project without `writable/db.sqlite`
  ("Undefined variable $projectRoot", then a `PDO::exec()` TypeError), so
  every console command failed before the first admin setup. It now creates
  the database.
- An empty `writable/db.sqlite` (left behind by that crash) is treated as a
  new database by both the console and the web app, so the schema gets
  loaded instead of failing with a missing `users` table.

## [0.8.0] - 2026-09-25

### Added
- **Site tasks.** The site's own scripts (imports, syncs, exports…) as PHP
  files in `site/tasks/`, runnable from the console (`task:list`,
  `task:run <name> --param=value`) and from the admin (new "Tasks" tab:
  parameter form incl. file upload, live output, run history). Per-task
  `roles`, typed `params` (`text`, `textarea`, `number`, `boolean`, `select`,
  `file`), `confirm`, `timeout`; one run at a time; cache flush after
  success; run records and log in `writable/logs/tasks/`.
- **Background runs.** Admin and hook runs start in the background — a
  detached `console task:worker` process, else `fastcgi_finish_request`, else
  inline (`tasks.runner` / `tasks.php` in `site/config.php`). The run page
  streams the output while it runs.
- **Hooks.** A task with `on: ['page.saved:/blog', 'collection.item.*:products', …]`
  runs on content events (`page.saved`, `page.moved`, `page.deleted`,
  `collection.item.saved`, `collection.item.deleted`) and gets the event
  payload via `$task->event()`.
- `CollectionRepository::save()` stores a new item without `filePath` at
  `<collection>/<slug>/item.txt`.

## [0.7.8] - 2026-09-25

### Added
- **Image thumbnails.** New Twig filters `|thumb(width, height = 0, fit)` and
  `|thumb_srcset([widths])` return signed `/thumb/...` URLs; the resized copy
  is generated with GD on first request and cached in `writable/cache/thumbs/`.
  Never upscales; SVG, GIF, external and non-media URLs pass through unchanged.
  `fit: 'cover'` crops to fill the box. EXIF rotation is applied (`ext-exif`).
- WebP output: `|thumb(600, format='webp')`, or `thumbs.format: 'webp'` in
  `site/config.php` for all thumbnails.
- Static thumbnails: with `thumbs.static: true` they are written to
  `public/thumb/…` (their own URL path), so the web server serves them without
  PHP after the first request.
- Console commands `thumbs:warm [baseUrl]` and `thumbs:clear`.
- The admin editor shows small previews in image lists and collection forms
  instead of the full-size originals; upload responses carry a `thumb` URL.

### Changed
- Markdown images in text blocks are served as thumbnails (max 1200 px wide,
  2x `srcset`, `loading="lazy"`). Set `thumbs.markdown` to another width, or
  `0` to keep the originals.

## [0.7.7] - 2026-09-25

### Fixed
- **Decimal numbers in collection items.** The `number` input in the
  collection item form had no `step`, so browsers rejected values like
  `96.1` on submit. Number inputs now default to `step="any"` everywhere.
- A `number` field whose stored value uses a decimal comma (`96,1`, e.g.
  after switching a `text` field to `number`) is shown as `96.1` instead of
  an empty input.

### Added
- Optional `step` key on `number` fields (block, page and collection
  schemas), alongside `min` / `max`.

## [0.7.6] - 2026-09-25

### Added
- **Page references in selects.** `options_from: pages` offers published
  pages (`pages:/blog` = descendants of `/blog`, optional `template:` filter,
  string or list). The stored value is the page's URL path; the new `page()`
  Twig function returns the live page or `null`. Label/sort/group fields:
  `title`, `slug`, `path`, `template`, `sort`, `date`, `parent`,
  `parent_title` and the page's own fields.
- **Editor-chosen collection.** `options_from: collections` (or
  `collections:a,b`) offers the items of several collections, one
  `<optgroup>` per collection; the stored value is `<collection>/<slug>`.
- `collection_item()` accepts a single `"<collection>/<slug>"` argument.
- Unit tests for both sources.

### Changed
- `FieldOptions` resolves every data source into one record shape, so
  `sort_by`, `group_by` and `option_label` behave the same for all of them.

## [0.7.5] - 2026-09-25

### Added
- **Page fields.** A template's `<template>.blocks.yaml` manifest can declare
  `fields:` (same schema format as block schemas: `text`, `textarea`, `image`,
  `file`, `number`, `select`, `boolean`, `color`, `list` with `item:`). They are
  edited in a "Page fields" panel above the page builder, stored in the page's
  front matter and exposed to the public template as typed, asset-resolved
  `fields` (plus a `page_fields(page)` Twig function for other pages).
- `blocks: false` in the manifest hides the page builder — a fields-only
  template. Fields and the builder combine freely.
- `Station0\Service\PageFields`, `Station0\Support\FrontMatter`,
  `Station0\Support\FieldSchema`, `MediaService::resolveFieldRefs()`; unit
  tests included.
- Block schemas now render `number` and `color` fields in the editor (they
  were previously skipped, so their values were lost on save).

### Changed
- Front matter supports YAML block values: a multi-line string is written as
  a literal block (`Key: |`) and arrays as indented YAML. Single-line values
  are unchanged and still read verbatim. Shared by pages and collection items.
- Block field inputs live in the shared `admin/templates/pages/_fields.twig`
  partial. A block field explicitly saved empty no longer reverts to its
  schema default in the editor.

### Fixed
- A collection `textarea` value containing line breaks corrupted the item
  file (only its first line survived).

## [0.7.4] - 2026-09-24

### Fixed
- `bin/console` failed with a symlinked dev install (path repository): it
  resolved the project root from the library's real path and required a
  non-existent `vendor/autoload.php`. It now mirrors
  `Bootstrap::findProjectRoot()` and walks up from the working directory.
- The item list page title of a grouped collection named the generic
  "Collections" section instead of its group.

## [0.7.3] - 2026-09-24

### Added
- **Collection groups.** `group: <Name>` in a collection's `_collection.yaml`
  moves it from the generic "Collections" tab into its own admin menu tab
  (`/admin/collection-groups/{id}`; a single-collection group links straight
  to its items). Optional `site/content/collections/_groups.yaml` sets
  per-group `label`, `icon` and `roles` (admins always have access; enforced
  on every collection route and the upload endpoint) and the tab order.
- `Station0\Service\CollectionGroups`, `collection_groups()` admin Twig
  function, `CollectionRepository::names()`; unit tests included.

## [0.7.2] - 2026-09-24

### Added
- **Select options from a collection.** A `select` field (block schemas, list
  item fields and collection schemas) can declare `options_from: collection:<name>`
  to offer one option per published collection item (value = item slug).
  Optional `group_by` (item field rendered as `<optgroup>`), `sort_by`
  (`-field` for descending; numeric-aware, decimal comma accepted),
  `option_label` template (`{title}`, `{slug}`, `{<field>}`) and `placeholder`.
- Static select `options` accept `{value: label}` maps and
  `[{value, label, group}]` entries besides the plain string list.
- `select` fields inside `list` items are now rendered as selects (previously
  they fell back to a text input).
- `Station0\Service\FieldOptions` resolves select options; unit tests included.

### Changed
- A stored select value that is no longer among the options is shown as a
  "(not found)" option instead of being silently replaced on save.
- Selects backed by a data source or declaring `placeholder` start empty
  instead of pre-selecting the first option.

## [0.7.1] - 2026-06-18

### Added
- **Per-template block restrictions.** A template can now declare which block
  types its pages may use and which blocks a new page starts with, via an
  optional sibling manifest `site/templates/<template>.blocks.yaml`:
  - `allowedBlocks: [...]` restricts the "+ Add block" palette to the listed
    types, in declared order. No declaration ⇒ all blocks (unchanged).
  - `defaultBlocks: [...]` pre-inserts those blocks into a **new** page of the
    template, each using its schema default field values. No declaration ⇒ a
    single empty `text` block (unchanged).
  - The two compose; pre-seeded blocks must be a subset of the allow-list.
    Unknown block names are ignored; an allow-list that filters empty falls
    back to the full palette rather than rendering an empty one.

  This is the block-level analogue of a page's `AllowedChildTemplates`
  (which restricts which child *templates* a page accepts).
- `Station0\Service\TemplateBlocks` resolves and caches the manifest per template.
- `BlockRegistry::defaults()` builds a seeded block from a block schema's default
  field values (shares per-field default logic with `snippet()`).
- PHPUnit test infrastructure (`phpunit.xml`, `composer test`) plus unit tests
  covering the allow-list, pre-seeding, and `BlockRegistry::defaults()`.

### Notes
- Backward compatible: templates without a manifest behave exactly as before.
- The new-page palette is server-rendered for the template the form opens with;
  switching the template `<select>` does not re-fetch it.

## [0.7.0] - 2026-06-13

### Security
- Fix path traversal in collection uploads (`MediaService` sanitizes
  `collectionName`/`itemSlug` and asserts containment in `collectionsDir`).
- Sanitize route-supplied collection `{name}`/`{slug}`.
- Escape raw HTML and drop unsafe links in CommonMark.
- Serve SVG with sandbox CSP + attachment disposition.
- Stop leaking exception text on setup; log instead.
- Block deleting your own account or the last admin.

### Changed
- **BREAKING:** Markdown raw HTML in text blocks is now escaped and
  `javascript:`/`data:` links are dropped. Audit existing page content that
  relied on inline HTML.
- Full i18n of controller messages (en/cs); removed dead code.

[0.10.2]: https://github.com/lexislav/station0/compare/v0.10.1...v0.10.2
[0.10.1]: https://github.com/lexislav/station0/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/lexislav/station0/compare/v0.9.3...v0.10.0
[0.9.3]: https://github.com/lexislav/station0/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/lexislav/station0/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/lexislav/station0/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/lexislav/station0/compare/v0.8.2...v0.9.0
[0.8.2]: https://github.com/lexislav/station0/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/lexislav/station0/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/lexislav/station0/compare/v0.7.8...v0.8.0
[0.7.8]: https://github.com/lexislav/station0/compare/v0.7.7...v0.7.8
[0.7.7]: https://github.com/lexislav/station0/compare/v0.7.6...v0.7.7
[0.7.6]: https://github.com/lexislav/station0/compare/v0.7.5...v0.7.6
[0.7.5]: https://github.com/lexislav/station0/compare/v0.7.4...v0.7.5
[0.7.4]: https://github.com/lexislav/station0/compare/v0.7.3...v0.7.4
[0.7.3]: https://github.com/lexislav/station0/compare/v0.7.2...v0.7.3
[0.7.2]: https://github.com/lexislav/station0/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/lexislav/station0/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/lexislav/station0/compare/v0.6.1...v0.7.0

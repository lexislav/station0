# Changelog

All notable changes to `lexislav/station0` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

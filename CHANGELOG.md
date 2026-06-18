# Changelog

All notable changes to `lexislav/station0` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.7.1]: https://github.com/lexislav/station0/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/lexislav/station0/compare/v0.6.1...v0.7.0

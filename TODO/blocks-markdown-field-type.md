---
title: "`type: markdown` field — rich-text editor in the admin, rendered automatically"
status: open
priority: low
area: blocks
created: 2026-10-27
related: [https://github.com/lexislav/station0/issues/27, src/Service/PageRenderer.php, src/Service/MediaService.php, admin/templates/pages/_fields.twig, src/Service/PageFields.php]
---

## Problem

Since the `markdown` Twig filter (issue #27), a block or page field can hold
Markdown as a plain `textarea` and the template renders it with
`{{ block.body|markdown }}`. The field itself knows nothing about Markdown:
the admin shows a bare textarea (no ToastUI editor, no image upload into the
text), and the template author has to remember the filter.

## Why it matters / why deferred

The filter covers the "Done when" of #27; a dedicated field type was a
"could" in the issue and touches the admin field partial, the JS
serializer, `PageFields` sanitizing and block asset resolution — a separate
change.

## Proposed fix

- New field type `markdown` (blocks, page fields, collection schemas).
- Admin: render it with the ToastUI editor used by `text` blocks
  (`pages/_fields.twig`, `collectFields()` in `pages/edit.twig`), uploads
  inserting page-local filenames.
- Render: `MediaService::resolveFieldRefs()` / `PageRenderer::resolveBlockAssets()`
  convert `markdown` values via `PageRenderer::markdown()` into a
  `Twig\Markup`, so the template prints `{{ block.body }}`. Watch out for
  double conversion if a template still applies `|markdown` — maybe keep the
  raw string and expose the HTML under another key instead.
- `PageFields::resolved()` does the same for page fields.

## Done when

- A schema field `type: markdown` gets the Markdown editor in the admin
  (block, page field, list item) and renders as HTML without a filter.
- Tests for block + page field rendering and image resolution.
- CLAUDE.md "Block schema format" lists the type.

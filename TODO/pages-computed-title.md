---
title: Computed title for pages (stream records), like collections' `title:` pattern
status: open
priority: low
area: pages
created: 2026-10-26
related: [src/Support/Placeholders.php, src/Service/CollectionRepository.php (applyComputedTitle), CLAUDE.md "Computed title"]
---

## Problem
Collections can build an item's title from its fields (`title:` in
`_collection.yaml`, modes readonly / editable / hidden). Pages with page
fields — typically stream records such as products or people under a stream —
still need a hand-typed title.

## Why it matters / why deferred
Same duplicate typing and drift as collections had. Deferred as phase 2 of
the collections feature: pages touch more moving parts (slug auto-fill and
`metatitle` placeholder hang off the title input in `pages/edit.twig`).

## Proposed fix
- Same `title:` key (string shorthand / `{from, input}`) in
  `<template>.blocks.yaml`, resolved by `TemplateBlocks`; placeholders from
  page fields (`PageFields`, `Page::$extra`), `{slug}`, `{template}`.
- Reuse `Placeholders::fill()` and the collection rules: stored in `Title:`,
  computed on save in `ContentRepository::save()`, `editable` override by
  comparison with the stored file.
- `PageController::store/update`: compute before the slug is derived; empty
  result → 422. `pages/edit.twig`: readonly / hidden title input, JS mirror
  (share it with `collections/form.twig` instead of a second copy).
- Optional, both kinds: `{field|label}` to use a select's option label
  (via `FieldOptions`) instead of the stored slug / path.

## Done when
- A template with `title:` gives new and edited pages the built title in all
  three modes; tasks saving pages get it too.
- Tests mirroring `CollectionComputedTitleTest`; CLAUDE.md "Page fields" and
  CHANGELOG updated.

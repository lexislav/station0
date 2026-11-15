---
title: Multilingual collections — `item.en.txt`, `collection()` per language
status: open
priority: high
area: content
created: 2026-11-14
related: [content-multilingual-extras.md, docs/multilingual.md, src/Service/CollectionRepository.php, src/Controller/Admin/CollectionController.php]
---

## Problem
Multilingual stage 1 (`docs/multilingual.md`) translates pages only.
Collection items (`item.txt`) exist once: `collection()`, `collection_item()`,
`options_from: collection:…` labels and `render_collection_item()` are the
same on `/about` and `/en/about`.

## Why it matters / why deferred
Banners, products, team members and shared blocks usually carry text that
needs translating, so a cs + en site still can't be fully translated. Kept
out of the first PR to keep it reviewable.

## Proposed fix
Same pattern as pages:
- `item.en.txt` next to `item.txt`. Merge in `CollectionRepository` keyed by
  `Languages::current()` / an `in($lang, $withFallback)` view. `_collection.yaml`
  fields get `translate: false`. The computed `title:` is built per language.
- Visibility per translation. `languageFallback` applies: in `404` mode an
  untranslated item is left out of `collection()`.
- `MediaService` collection branch: refuse `item.<code>.txt` (only `item.txt`
  is blocked today, and `.txt` uploads are allowed for items, so check that
  an upload can't take the name either).
- Admin: language tabs in `collections/form.twig`, badges in the item table,
  delete translation. `collection.item.saved` gets `lang`.
- Render cache: `render_collection_item()` builds a `Page` with `lang`.

## Done when
- `collection('x')` on `/en/…` returns English items with fallback, and
  sites without `languages` are unchanged.
- Tests in `tests/Multilingual/` for merge, shared fields, computed title,
  media guard and admin save.

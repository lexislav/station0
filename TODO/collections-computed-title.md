---
title: Record title computed from other fields ("{name} {surname}"), title input readonly / hidden
status: open
priority: medium
area: collections
created: 2026-10-14
related: [src/Service/FieldOptions.php (label()), docs: CLAUDE.md "Collections"]
---

## Problem

Every collection item needs a hand-typed `Title`, even when the title is just a
combination of other fields — a `people` collection with `name` + `surname`, a
`products` collection with `brand` + `model`. Editors type the same data twice,
and the title drifts when a field is corrected but the title is not.

The content type (collection schema) should be able to say "the title is
`{name} {surname}`" and choose whether the editor still sees the title input:
editable, readonly (shown, filled automatically), or hidden.

## Why it matters

The title is what the admin lists, select `option_label` (`{title}` default),
hook payloads (`title`), `<title>` in admin and templates all use. Making it a
derived value removes duplicate typing and keeps those places consistent.

## Proposed fix

### Schema (`_collection.yaml`)

```yaml
label: People
title:
  from: "{name} {surname}"   # placeholders = schema field names, {slug}, {collection}
  input: readonly            # editable | readonly | hidden   (default: readonly)
fields:
  name:    { type: text, label: Name }
  surname: { type: text, label: Surname }
```

Shorthand `title: "{name} {surname}"` = `from` with the default `input`.
No `title` key ⇒ today's behaviour (plain required input).

- `editable` — the input is pre-filled live from the fields while the editor
  has not touched it; once typed into, the posted value wins (like the slug
  auto-fill in `pages/edit.twig`).
- `readonly` — input shown but `readonly`, kept in sync by JS for preview;
  the server **ignores** the posted value and recomputes.
- `hidden` — no input at all; the server computes it.

### Store the computed title, don't compute on read

The result is written to `Title:` in `item.txt` as now. Then `item.title`,
`collection()`, `option_label`, the items list, hooks and existing site
templates keep working without changes, and a schema change does not silently
re-title every item at render time.

### Where the computation lives

1. Extract the placeholder interpolation from `FieldOptions::label()` into a
   shared helper (e.g. `Station0\Support\Placeholders::fill(string $tpl,
   array $values): string`) — `{key}` case-insensitive, scalars only, missing
   → `''`, collapse repeated whitespace, trim. `FieldOptions` reuses it.
2. `CollectionRepository::titleSpec(string $name): ?array` — normalizes the
   `title` key (string shorthand / dict, invalid `input` → `readonly`).
3. `CollectionRepository::computeTitle(CollectionItem $item): ?string` —
   fills `from` with `extra` + `slug` + `collection`.
4. Apply it in `CollectionRepository::save()` (not only the controller), so
   site tasks / imports that save through the repository get the same titles.
   Mode `editable` keeps a non-empty posted title; `readonly` / `hidden`
   always overwrite.
5. `CollectionController::store()`: today it rejects an empty posted title
   *before* reading fields, and derives the slug from it. Reorder: extract
   extra fields first, compute the title, then derive the slug from the
   computed title. An empty computed title (all referenced fields empty) →
   form error (`errorKey` e.g. `collections_title_from_empty`, 422) instead of
   the current silent redirect.
6. `update()`: recompute the same way; slug unchanged unless the editor edits
   it (current behaviour).

### Admin form (`admin/templates/collections/form.twig`)

- Pass the normalized spec to the template (`titleSpec`).
- `hidden`: drop the title `<div class="field">`, keep a note under the
  heading ("Title is built from Name, Surname").
- `readonly`: `readonly` input (+ hint), no `required`, not `autofocus` —
  focus the first schema field instead.
- Small JS: listen to `input`/`change` on the referenced field inputs and
  fill the title (+ the `<h1>` on new items) with the same rules as the PHP
  helper. Boolean / select / image fields use their raw stored value.

### Known limits (document, don't solve in v1)

- `{field}` uses the **stored** value: a select stores a slug / path, an image
  a filename, a boolean `true`/`false`. A later `{author|label}` could resolve
  the option label through `FieldOptions`.
- Existing items keep their typed title until saved again. Optional console
  command `collections:retitle <name> [--dry-run]` to recompute all items.

### Phase 2 — pages (stream records)

Same `title:` key in `<template>.blocks.yaml`, placeholders from page fields
(`PageFields`). Touches `PageController::store/update`, `pages/edit.twig`
(title input + the slug auto-fill and `metatitle` placeholder that hang off
it) and `TemplateBlocks`. Separate PR after collections land.

## Done when

- `_collection.yaml` `title` key (shorthand + dict) works for all three modes.
- Title is recomputed in `CollectionRepository::save()`; tasks saving items get
  computed titles.
- Create: slug derived from the computed title; empty computed title → 422
  with a message, nothing written.
- Tests:
  - `Placeholders::fill()` — case-insensitivity, missing keys, whitespace
    collapse; `FieldOptions` labels unchanged.
  - Repository: `readonly` / `hidden` overwrite a posted title, `editable`
    keeps it, no `title` key = unchanged behaviour.
  - Controller: create with hidden title (no `title` posted) → item with
    computed title + slug; empty fields → 422.
- Docs: CLAUDE.md "Collections" (schema example + modes + limits),
  CHANGELOG `Unreleased`.

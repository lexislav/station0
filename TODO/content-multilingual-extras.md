---
title: Multilingual stage 3 — per-block shared fields, Accept-Language, admin helpers
status: open
priority: low
area: content
created: 2026-11-15
related: [content-multilingual-collections.md, docs/multilingual.md, src/Service/ContentRepository.php, src/Service/FieldOptions.php]
---

## Problem
Gaps left after translated slugs (`docs/multilingual.md` → "Limits"):
- **Blocks translate as a whole body.** A `translate: false` field inside a
  block (an image in a gallery) has to be repeated in every translation.
- **No `Accept-Language` redirect from `/`.**
- **`options_from: pages`** labels use default-language titles.
- **Admin:** no translation-status filter in the tree, no "copy from
  default" button, no side-by-side view. Redirect rules with `lang:` are
  not listed in the page panel or counted in the badges.

## Why it matters / why deferred
Each one is a separate design question. Block merging has to decide what
happens when the translated block list differs in length or order. An
`Accept-Language` redirect affects caching and SEO.

## Proposed fix
- Blocks: merge block lists by index and type, taking `translate: false`
  fields from the default body. Fall back to the translated block as-is
  when the types differ at that index.
- `/` (only `/`, GET, no Referer from the site): 302 to the best
  `Accept-Language` match, with `Vary: Accept-Language` and opt-in config.
- `options_from: pages`: label in the admin's interface language when it is
  a site language.
- Admin: filter "untranslated in <lang>", "copy from default" on an
  untranslated tab, `lang:` rules in the redirects panel (via `publicPath`).

## Done when
- A gallery with `translate: false` images shares them across languages.
- Each item above has its tests and docs.

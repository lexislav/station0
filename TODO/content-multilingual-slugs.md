---
title: Multilingual stage 3 — translated slugs, per-block shared fields, prefix-aware tooling
status: open
priority: medium
area: content
created: 2026-11-14
related: [content-multilingual-collections.md, docs/multilingual.md, src/Service/ContentRepository.php, src/Plugin/Redirects/, src/Service/FieldOptions.php]
---

## Problem
Gaps left by multilingual stage 1 (`docs/multilingual.md` → "Limits"):
- **Slugs are shared.** `/en/o-nas` instead of `/en/about-us`.
- **Blocks translate as a whole body.** A `translate: false` field inside a
  block (an image in a gallery) has to be repeated in every translation.
- **Tooling knows default URLs only.** `thumbs:warm` fetches default-language
  pages. `options_from: pages` stores unprefixed paths (correct, but labels
  are default-language titles). The redirects 404 log records unprefixed
  paths, so `/en/x` and `/x` misses are merged.
- No `Accept-Language` redirect from `/`.

## Why it matters / why deferred
Translated slugs matter for SEO on real cs + en sites, but they touch
routing, the redirects plugin, AccessPolicy `public` globs and every place
that maps a URL to a folder. That needs its own design pass after stage 1
has been used on a site.

## Proposed fix
- `Slug:` in a translation file. `ContentRepository::find()` in a language
  resolves segment by segment against translated slugs (an index of
  slug → dir per parent, or the compiled index from `content-index.md`).
  `Page::$urlPath` stays the folder path (identity). Add `Page::url($lang)`
  or keep `url()` in Twig, resolving the translated path.
- Redirects: `recordMove()` and admin renames of a translated slug add
  `/en/old` → `/en/new`. Rules may carry `lang:` to scope them.
- AccessPolicy `public` globs: match against the folder path (unchanged)
  and document it.
- Blocks: merge block lists per index and type, taking `translate: false`
  fields from the default body. Decide what happens when the block lists
  differ in length or order.
- `thumbs:warm` per language. A `lang` column in the 404 log.
- Admin: translation-status filter in the tree, "copy from default"
  button, side-by-side view.

## Done when
- `/en/about-us` serves `about/page.en.txt` with `Slug: about-us`, and the
  old shared slug redirects. Existing stage-1 sites are unchanged.
- Tests for routing, redirects and access with translated slugs.

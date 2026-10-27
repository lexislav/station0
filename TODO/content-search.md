---
title: Full-text search over pages and collections (SQLite FTS5 index)
status: open
priority: medium
area: content
created: 2026-10-27
related: [content-index.md, content-multilingual.md, plugin-content-api.md]
---

## Problem
There is no search at all — no public site search and no search in the admin
(page tree, collection item lists). Bolt has both.

## Why it matters / why deferred
Expected on any site with more than a handful of pages, and editors need it
in the admin once collections grow. Deferred because it should share the
freshness model of `content-index.md` instead of inventing its own.

## Proposed fix
- **Index:** SQLite FTS5 table in a separate file `writable/cache/search.sqlite`
  (disposable, not the auth DB): `kind` (page / item), `path` or
  `collection/slug`, `lang`, `title`, `summary`, `body` (markdown → plain
  text; block lists → text of `text` / `textarea` fields), selected fields,
  plus status / dates / access for filtering. Tokenizer
  `unicode61 remove_diacritics 2` so "cesky" finds "česky"; no Czech stemming
  (document it; prefix queries `term*` cover most of it).
- **Updates:** incremental on `page.saved` / `page.moved` / `page.deleted` /
  `collection.item.*` events; full rebuild with `console search:rebuild` and
  whenever `content.version` says the content changed outside the admin.
- **What is indexed:** schema option `search: false` on fields and
  `_collection.yaml` (`search: false` / list of fields) — collections are
  headless, so they are opt-in for the public search, always on in the admin.
- **Public:** `search(query, {limit, offset, kinds, collections})` Twig
  function → results with `title`, `url`, `snippet` (FTS5 `snippet()`,
  escaped, `<mark>`), `score`; live only, `AccessPolicy` applied (members-only
  pages never appear for anonymous visitors), rate-limited if exposed as a
  route. Optional `search.path` route rendering `search.twig`.
- **Admin:** search box above the page tree and collection lists (drafts
  included, role/group rules applied).
- **API plugin:** `GET /api/search?q=` once `plugin-content-api.md` lands.
- FTS5 missing in the PHP SQLite build ⇒ fall back to `LIKE` with a warning.

## Done when
- Tests: diacritics-insensitive match, drafts / scheduled / members-only
  excluded publicly, included in admin; index updates on save / rename /
  delete; snippet escaping (no HTML injection from content).
- `docs/search.md`, CLAUDE.md section, skeleton example `search.twig`.

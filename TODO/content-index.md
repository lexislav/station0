---
title: Compiled content index — answer listings and lookups without parsing files per request
status: open
priority: high
area: content
created: 2026-10-27
related: [content-search.md, content-multilingual.md, plugin-content-api.md, src/Service/ContentRepository.php, src/Service/CollectionRepository.php, src/Plugin/Redirects/Compiler.php]
---

## Problem
Every request that lists content scans directories and parses `.txt` files:
`ContentRepository::scanDir()` / `find()` and `CollectionRepository::items()`
memoize only for the current request (`$parsedCache`). `FileCache` holds
rendered page bodies, but the outer template — with its `child_pages()`,
`nav_pages()`, `collection()` and `options_from` calls — runs every time.
Cost grows linearly with the number of pages and items: fine for hundreds,
noticeable at thousands, and there is no way to query (filter / sort / page /
reverse relations) other than loading everything.

## Why it matters / why deferred
It is the foundation for the gaps against Bolt: search (`content-search.md`),
multilingual lookups (`content-multilingual.md`) and the API
(`plugin-content-api.md`) all need fast metadata access, and it keeps
Station0's speed advantage as sites grow. Not urgent for today's small sites,
so it waits for a design pass; do it before the multilingual work.

## Proposed fix
Files stay the single source of truth; the index is derived and disposable.

- **Compiled PHP array** `writable/cache/content-index.php` (same pattern as
  `Redirects\Compiler` → `redirects.php`, served from opcache shared memory):
  per page — url path, parent, dir, content file, template, status /
  publishAt / expireAt / listing / cascade / access / group, sort, title,
  declared page fields (raw strings); per collection item — collection, slug,
  status / dates, schema fields; reverse relation map for `select` values
  (`options_from` targets → referrers).
- **Freshness, one `stat`:** every write through `ContentRepository` /
  `CollectionRepository` (save, rename, move, delete, schema save) touches
  `writable/cache/content.version`; the index rebuilds when the stamp is newer
  than the compiled file. Hand edits / `git pull` / rsync deploys:
  `php vendor/bin/console index:rebuild` (document it for deploy scripts) and
  an optional `index.check: mtime` mode that walks dir mtimes for dev.
  Pitfall: a dir mtime changes only for direct children, not recursively —
  never rely on the root dir mtime alone.
- **Rebuild:** under `flock`, tmp file + rename (as redirects do); a failed
  rebuild keeps serving the last good index and logs.
- **Consumers:** `children()`, `navChildren()`, `all()`, `find()` resolution,
  `CollectionRepository::items()` / `find()`, `FieldOptions` (`options_from`),
  `NavGroups`, `VisibilityHorizon` (next transition straight from the index)
  read the index; full `Page` / `CollectionItem` objects (body, extra) are
  still parsed lazily from the file when a template needs them.
- **Query helper** for templates and the API: `pages({parent, template,
  where, sort, limit, offset})`, `collection(name, {where, sort, limit})` —
  evaluated over arrays in memory, Visibility + AccessPolicy applied there.
- Very large collections (≫10k items) can later move to an SQLite mirror with
  `json_extract`; keep the repository interface so callers don't change.
- Optional: full-page cache for anonymous visitors on top (keyed by path +
  access level + language), invalidated by the same version stamp.

## Done when
- Listing functions and collection reads do not open `.txt` files when the
  index is fresh (test with a counting filesystem wrapper or parse counter).
- Admin save / rename / move / delete, task writes via repositories and
  `index:rebuild` all leave the index correct; a hand-edited file is picked
  up after `index:rebuild` (and in `mtime` mode without it).
- Benchmark in `--group bench`: 5,000 pages / 10,000 items, listing request
  time before vs. after.
- CLAUDE.md "Architecture → Caching" and skeleton AGENTS.md (deploy step).

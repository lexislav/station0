---
title: Detect concurrent edits — refuse a save when the file changed since the form was opened
status: open
priority: medium
area: admin
created: 2026-10-27
related: [content-history.md, src/Controller/Admin/PageController.php, src/Controller/Admin/CollectionController.php]
---

## Problem
Page and collection item saves overwrite whatever is on disk. Writes are
atomic (tmp + rename in `ContentRepository::save()` /
`CollectionRepository::save()`), but nothing checks whether the file changed
after the editor opened the form. Two editors on the same page — or an editor
plus a site task / import / `git pull` — means the last save silently wins
and the other changes are lost.

## Why it matters / why deferred
Silent data loss, and it gets likelier with more editors and with tasks that
write content. Cheap to fix; not done yet because the sites so far had one
editor.

## Proposed fix
Optimistic locking:
- Edit forms carry a hidden `base_version` = content file mtime + size (or a
  short hash of the file) at render time.
- `update` compares it with the file on disk before saving; mismatch ⇒ 409
  with the form re-rendered, the editor's input kept, and a notice
  ("Changed by someone else since you opened it") offering *reload* or
  *overwrite anyway* (re-post with `force_overwrite=1`).
- Same for collection items and rename/move (check the source still matches).
- JS autosave / preview requests, if any, send the version too.
- Out of scope: real-time presence / locks ("X is editing").

## Done when
- Tests: stale `base_version` ⇒ 409 and file untouched; matching version
  saves; forced overwrite saves; works for pages and collection items.
- Admin strings in `admin/lang/{en,cs}.php`.

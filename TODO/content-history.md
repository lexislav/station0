---
title: Content history — previous versions of pages and items, restore from the admin
status: open
priority: low
area: content
created: 2026-10-27
related: [admin-edit-conflict-guard.md, src/Service/ContentRepository.php, src/Service/CollectionRepository.php]
---

## Problem
A save replaces the content file; the previous version is gone unless the
site keeps `site/content/` in git and someone commits. Editors cannot see
what changed or undo a bad edit / delete from the admin.

## Why it matters / why deferred
Flat files make this easier than in a database CMS, so it is a cheap
advantage to claim. Low priority: developers already get history from git.

## Proposed fix
- Before `save()` / `delete()` overwrites a content file, copy it to
  `writable/history/<kind>/<path-hash>/<Ymd-His>-<user>.txt` (plus a small
  JSON line: user, action, original path). Assets are not versioned in v1.
- Retention: keep last N versions and/or D days (`history.keep`,
  `history.days`), pruned on write; `history: false` disables.
- Admin: "History" on the edit page — list of versions, plain-text diff
  against current, *Restore* (= a normal save, so it is itself versioned and
  fires `page.saved`). Deleted pages/items: a "Recently deleted" list with
  restore while the copy exists (page dir assets are deleted today — decide
  whether delete should move the dir to history instead).
- Optional git mode later: commit on save when `site/content` is a repo.

## Done when
- Tests: save keeps the previous version, retention prunes, restore writes
  the old content and records a new version, delete → restore.
- CLAUDE.md note; `writable/history/` documented for backups.

# TODO

Open work for the library, one Markdown file per item, so each one can carry
enough context to be picked up later without digging through old sessions.

## File name

`<area>-<short-slug>.md`, lower-case, hyphens — e.g. `admin-safetemplate-child-only.md`.
The area is a loose prefix (`admin`, `content`, `media`, `access`, `tasks`,
`console`, `docs`…) so related items sort together.

## Format

```markdown
---
title: One-line summary
status: open            # open | in-progress | blocked
priority: low           # low | medium | high
area: admin
created: 2026-10-14
related: []             # other TODO files, PRs, docs
---

## Problem
What is wrong or missing, and where (file:line, class, route).

## Why it matters / why deferred
Severity, who is affected, why it was not done right away.

## Proposed fix
The approach, including pitfalls to avoid.

## Done when
Concrete acceptance criteria — tests to add, docs to update.
```

## Lifecycle

- New item → new file with `status: open`.
- Started → `status: in-progress` (optionally note the branch).
- Finished → **delete the file** in the same PR that fixes it; the PR / commit
  history is the record. Mention the fix in `CHANGELOG.md` as usual.
- Dropped → delete the file and say why in the commit message.

TODO-only changes never trigger a release: commit straight to `main` as
`chore(todo): …`, with no version bump, `CHANGELOG.md` entry or tag.

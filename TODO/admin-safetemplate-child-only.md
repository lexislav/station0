---
title: safeTemplate() accepts child-only templates the palette hides
status: open
priority: low
area: admin
created: 2026-07-16
related: [PR #4]
---

## Problem

`PageController::safeTemplate()` (`src/Controller/Admin/PageController.php`)
validates the posted template name — path traversal and the `layout` bypass are
closed — but it still accepts **child-only** templates: templates listed in some
page's `AllowedChildTemplates` that `availablePageTemplates()` deliberately
hides from the global palette. A crafted POST can therefore give a page a
template that is not offered for its position in the tree.

## Why it matters / why deferred

Low severity, editor-only, cosmetic: worst case an authenticated editor makes a
page render an out-of-context template. No traversal, no disclosure. The
parent → child restriction that matters is already enforced by
`ContentRepository::assertChildTemplateAllowed()`. Deferred after PR #4 was
merged.

## Proposed fix

Do **not** blanket-reject `ContentRepository::childOnlyTemplates()` — that
breaks legitimate re-saves of a correctly placed child page (e.g. a `gallery`
page under a `fotogalerie` stream).

`safeTemplate()` has no parent context today. Thread the resolved parent `Page`
into it and reject a child-only template only when that parent does not
sanction it, mirroring the parent-aware logic in `availablePageTemplates()`.

## Done when

- `safeTemplate()` is parent-aware for child-only templates.
- Regression tests:
  - a child-only template is rejected (falls back) under an unrestricted parent;
  - the same template is accepted under a parent whose `AllowedChildTemplates`
    lists it, including re-saving an existing child page.

---
title: Offer "redirect to parent / 410" when deleting a page that has incoming redirects
status: open
priority: low
area: content
created: 2026-10-26
related: [docs/redirects.md, src/Plugin/Redirects/, admin/templates/pages/list.twig (delete confirm), src/Controller/Admin/PageController.php (delete)]
---

## Problem

Deleting a page leaves its URL (and its subtree's) as a plain 404. Rules
that pointed to it (exact aliases, auto subtree rules) now lead to a 404
too — the redirects admin marks them "target is not a live page", but
nothing happens at delete time. The redirect manager design (shipped in
0.10.1) deferred this.

## Why it matters / why deferred

SEO and old links: a deleted product or article should usually 301 to its
section or answer 410. Deferred to keep the first redirects PR focused; it
also needs a way for a plugin to take part in the core delete confirm.

## Proposed fix

- When the redirects plugin is on, the page delete confirm (Structure tree
  row menu, edit form) asks: "Redirect to the parent" (301 subtree rule
  `/deleted/{path*}` → parent) / "Answer 410 Gone" / "Keep as 404", and says
  how many incoming rules point here (`Matcher::incoming()`).
- Needs a small plugin hook on delete (e.g. the `page.deleted` payload
  carrying the choice posted with the delete form, or a plugin-provided
  confirm partial); incoming rules are then rewritten to the parent / made
  410 by `Store`.

## Done when

- Deleting with "redirect to parent" adds one subtree rule and retargets
  incoming rules; "410" turns them into 410 rules; "keep" changes nothing.
- Tests in `tests/Plugin/Redirects/`; docs/redirects.md updated.

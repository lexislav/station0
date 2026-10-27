---
title: Read-only JSON content API as a bundled plugin (`api`) — no GraphQL
status: open
priority: medium
area: plugin
created: 2026-10-27
related: [content-index.md, content-search.md, docs/plugins.md, src/Service/PageFields.php, src/Service/AccessPolicy.php, src/Support/Visibility.php]
---

## Problem
Content is reachable only as rendered HTML. There is no way for a JS
front end, mobile app, another site or a build step to read pages and
collections as data — Bolt offers REST (API Platform) + GraphQL, including
writes since 5.1.

## Why it matters / why deferred
Needed for headless use (collections are already headless in the admin, but
not over HTTP) and for sites that feed widgets / apps. Not needed by a typical
brochure site, so it belongs in an opt-in plugin, not core. Deferred until the
plugin API settles (`plugin-admin-settings.md`).

GraphQL is deliberately out of scope: a schema generator over dynamic
block/field schemas, query-cost limiting and a second auth surface are a lot
of attack area for little gain on flat-file content. Writes are out of scope
too — imports and syncs go through site tasks; form posts through site
actions. Revisit both only with a concrete site that needs them.

## Proposed fix
Bundled plugin `Station0\Plugin\Api\Plugin`, `publicRoutes` under a
configurable prefix (default `/api`):

- `GET /api/pages?parent=/blog&template=article&limit=&offset=`
- `GET /api/pages/{path}` — meta, `fields` (via `PageFields`, asset refs as
  absolute URLs), optional `?render=html` body
- `GET /api/collections/{name}` and `/api/collections/{name}/{slug}`
- Filtering / sorting / paging go through the query helper of
  `content-index.md`, so list endpoints never parse files per request.
- Options: `collections` / `pages` allow-lists (nothing exposed unless listed),
  `cors` origins, `maxAge`, optional bearer `tokens` for members-only content.

Security rules (Bolt's 2026 advisories are the checklist — unfiltered relation
endpoint, predictable preview secret):
- live content only (`Visibility` / `isLive()`), never drafts or previews;
- `AccessPolicy` applies exactly as for HTML pages;
- only schema-declared fields leave the server — never raw `page.extra`;
- `select` relations resolve to live, allowed targets only;
- responses `ETag` + `Cache-Control`, cached in `FileCache` per access level.

## Done when
- Plugin enabled via `'plugins' => ['api' => [...]]`; off ⇒ no routes.
- Tests: drafts / scheduled / expired / members-only never appear (list and
  detail, also via relations); allow-lists; CORS; ETag 304.
- `docs/api.md` + CLAUDE.md section.

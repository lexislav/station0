---
title: Sanitize SVG uploads and lock in the SVG response headers with tests
status: open
priority: low
area: media
created: 2026-10-27
related: [src/Controller/AssetController.php, src/Service/MediaService.php]
---

## Problem
Page and collection uploads accept SVG (`MediaService::ALLOWED_MIME`) and store
the file byte-for-byte, so an editor can upload an SVG with `<script>`,
`on*` handlers, `<foreignObject>`, `javascript:` links or a `DOCTYPE` with
entities.

`AssetController::media()` already neutralizes this when the file is served
through `/media`: `Content-Security-Policy: default-src 'none'; style-src
'unsafe-inline'; sandbox`, `Content-Disposition: attachment` and
`X-Content-Type-Options: nosniff`. But:

- no test asserts those headers — a refactor of `media()` / `send()` could drop
  them silently;
- the protection lives only in that one code path. Any other way the stored
  file reaches a browser (a plugin serving assets, a web-server rule exposing
  `site/content/`, an editor opening a downloaded copy, a future static-media
  mode) serves the raw script;
- `attachment` means a direct link to an SVG downloads instead of showing it.

## Why it matters / why deferred
Today's exposure is low: it needs a malicious or compromised editor account,
and `<img>` / CSS use of SVG never runs scripts. Bolt shipped an editor →
RCE / XSS chain via uploads in 2026 (GHSA-m2j9-f9xw-xxgw), so this is the
kind of hole that gets found. Deferred because the header mitigation already
covers the route that exists.

## Proposed fix
1. Tests first: `/media/.../x.svg` responds with the CSP sandbox,
   `nosniff` and `attachment`; collection SVG (`/media/_collections/...`) too.
2. Sanitize on upload in `MediaService` (shared by page + collection uploads),
   allow-list based: parse with `DOMDocument` + `LIBXML_NONET`, no entity
   substitution; reject any DOCTYPE; drop `script`, `foreignObject`,
   `iframe`/`embed`/`object`, every `on*` attribute, `href`/`xlink:href` that
   are not `#fragment` or `data:image/*`; reject files that do not parse.
   Alternative: `enshrined/svg-sanitize` (one dependency, well maintained) —
   decide on dependency weight vs. own allow-list.
3. Once uploads are sanitized, consider serving SVG `inline` (keep the CSP
   sandbox) so direct links display.
4. Existing SVGs: a console command `assets:sanitize-svg [--dry-run]`.

## Done when
- Header tests exist and pass; sanitizer tests cover script, `on*`,
  `foreignObject`, `javascript:` href, external href, DOCTYPE/entities, and
  a harmless icon passing through visually unchanged.
- Upload of a malicious SVG is cleaned (or rejected with a clear admin error).
- CLAUDE.md "File uploads & media" mentions the sanitizing.

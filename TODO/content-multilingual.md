---
title: Multilingual content — translated pages, collection items and site strings
status: open
priority: high
area: content
created: 2026-10-27
related: [content-index.md, content-search.md, src/Service/ContentRepository.php, src/Service/CollectionRepository.php, src/Support/FrontMatter.php, src/Service/AccessPolicy.php, src/Plugin/Redirects/]
---

## Problem
Station0 has one language per site. `admin_locale` translates only the admin
UI (`admin/lang/*.php`); pages, collection items, page fields, blocks and the
site's own UI strings exist once. There is no language in the URL, no
`hreflang`, no language switcher data, no per-language cache.

## Why it matters / why deferred
This is the biggest functional gap when replacing Bolt (which has
multilingual content fields) on Czech + English sites. The only workaround
today is a separate Station0 install per language, which duplicates assets,
structure and editing. Deferred because it cuts across nearly every service
— it needs a design decision before code.

## Proposed fix
Design to settle first (write it up in `docs/multilingual.md`):

- **Storage — Kirby-style translation files** in the same page dir:
  `page.txt` (default language) + `page.en.txt`, `item.txt` + `item.en.txt`.
  Assets and child dirs stay shared, so the tree and page-local media are
  unchanged. A translation file holds only translated keys; missing keys fall
  back to the default language. Pitfall: `ContentRepository::contentFileIn()`
  treats several `*.txt` as an error/ambiguity and uploads reject `.txt` —
  both must learn the `<name>.<lang>.txt` pattern.
- **Config:** `'languages' => ['cs' => [...], 'en' => [...]]`, first = default;
  `prefixDefault` (whether `/cs/` appears in URLs). No `languages` ⇒ today's
  behaviour, byte-for-byte.
- **URLs:** `/{lang}/{path}`; optional translated slug (`Slug:` in the
  translation file) — decide whether v1 supports it (it touches redirects,
  `options_from: pages` values, AccessPolicy `public` globs).
- **Schema:** fields/blocks translatable by default, `translate: false` to
  share a value (price, image) across languages.
- **Visibility:** `Status` / `PublishAt` per translation (an untranslated or
  draft translation = 404 or fallback, configurable).
- **Twig:** `lang` global, `page.translations` (for switcher + `hreflang`),
  `url(page, lang)`, site strings via `t('key')` from `site/lang/<lang>.yaml`.
  All page functions (`page()`, `child_pages()`, `collection()`…) resolve in the
  current language.
- **Cache:** `PageRenderer` / `FileCache` keys include the language.
- **Admin:** language tabs on page / item edit; tree shows translation status.
- **Everything that knows paths:** AccessPolicy, redirects plugin (rules per
  language prefix, `recordMove`), task hook payloads (`lang`), thumbs warm,
  `options_from: pages`.

Build on `content-index.md` (land it first): the index carries `lang` per
entry, so translation lookup and fallback are array reads, not file probes.

Ship in stages: (1) storage + routing + Twig for pages, (2) collections,
(3) admin UX polish, translated slugs.

## Done when
- A site with `languages` configured serves `/cs/...` and `/en/...` from one
  content tree with fallback; a site without it is unchanged (existing tests
  green).
- Tests for FrontMatter merge/fallback, routing, cache separation, visibility
  per translation, AccessPolicy + redirects with prefixes.
- `docs/multilingual.md`, CLAUDE.md section, skeleton AGENTS.md updated.

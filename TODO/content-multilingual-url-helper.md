---
title: url() — leave /media, /thumb and admin alone; translate ancestors of untranslated pages
status: open
priority: medium
area: content
created: 2026-11-27
related: [src/Bootstrap.php, src/Service/ContentRepository.php, src/Middleware/LanguageMiddleware.php, docs/multilingual.md]
---

## Problem
Found while switching the skeleton templates to `url()` (get-station0#9):

- **`url('/media/…')` gets a prefix.** In English `url('/media/blog/x.pdf')`
  returns `/en/media/blog/x.pdf`, which is a 404. The same holds for
  `/thumb/…` and the admin path. `LanguageMiddleware::localizePath()` already
  skips these (`unprefixable()`), but the Twig `url()` in `Bootstrap` does
  not. A relative path (`url('foo')`) also becomes `/foo`.
- **Untranslated page under a translated parent.** With `blog/blog.en.txt`
  holding `Slug: clanky-co`, `url('/blog/categories')` in English is
  `/en/blog/categories`, not `/en/clanky-co/categories`, because
  `ContentRepository::publicPathOf()` falls back to the whole folder path when
  the leaf isn't a page in that language. It still works through the
  canonical 301, but costs a redirect (and with `languageFallback: content`
  the URL shown is the folder one).

## Why
Templates are told to use `url()` for every internal link. Editor-typed field
values (CTA links, datasheets) and `/media` URLs then can't safely go through
it, so template authors have to know which values to wrap.

## Proposed fix
- Share one path-localizing function between `url()` and
  `LanguageMiddleware::localizePath()`: leave external, relative, already
  prefixed and unprefixable paths alone.
- `publicPathOf()`: when the leaf has no page, map the longest ancestor that
  does and append the remaining segments.

## Done when
`url('/media/…')`, `url('/thumb/…')`, `url('/admin')` and `url('foo')` are
returned unchanged; `url('/blog/categories')` is `/en/clanky-co/categories`;
tests cover both; docs/multilingual.md updated.

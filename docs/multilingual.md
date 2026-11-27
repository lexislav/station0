# Multilingual content

Station0 (≥ 0.11) can serve one content tree in several languages. Each
page keeps its folder, assets and sub-pages. A translation is one more file
next to the page's content file.

This release covers **pages**, including translated slugs. Collections and
per-block shared fields come later (see `TODO/`).

## Turning it on

```php
// site/config.php
'languages' => [
    'cs' => ['label' => 'Čeština'],                     // first = default language
    'en' => ['label' => 'English', 'locale' => 'en_GB'],
],
'languageFallback' => '404',   // '404' (default) | 'content'
```

- The **first** language is the default. It has no URL prefix: `/about`.
- Every other language is served under its code: `/en/about`, `/en` (home).
- Codes are 2–3 letters, optionally with a region (`pt-br`). A top-level page
  slug that equals a language code is shadowed by that language.
- If `languages` is missing, or lists only one language, the site behaves
  exactly as before.

`/cs/about` (the default code as a prefix) is an ordinary path and returns
404. The admin, `/media` and `/thumb` never take a prefix.

## Storage

```
site/content/pages/
  page.txt            ← home, default language
  page.en.txt         ← home, English
  about/
    page.txt
    page.en.txt
    team.jpg          ← shared by every language
```

A translation file is named after the content file: `<template>.<code>.txt`.
It holds the translated keys and its own body:

```
Title: About us
Slug: about-us
Subtitle: Who we are
Status: published
---
English body (markdown or block list)
```

When a page is read in English, `page.en.txt` is merged over `page.txt`:

- Keys in the translation win. Keys it doesn't have fall back to the default file.
- An empty translated body falls back to the default body.
- **Structure keys always come from the default file:** `Template`, `Sort`,
  `AllowedChildTemplates`, `Group`, `Listing`, `Cascade` and `Access`.
- **Shared page fields:** a template field with `translate: false` is read
  from the default file only. Use it for prices, images and similar values.

```yaml
# site/templates/product.blocks.yaml
fields:
  subtitle:
    type: text          # translated (default)
  price:
    type: number
    translate: false    # one value for every language
```

A translation file is never a page of its own. A folder that holds only
`page.en.txt` is not a page. A translation file is never served from `/media`.

## Translated slugs

`Slug:` in a translation file gives the page its own URL segment in that
language. With `o-nas/page.en.txt` holding `Slug: about-us`, the English
page is `/en/about-us`. A translation without `Slug:` uses the folder name.

- Lookup goes segment by segment. At each level a translated slug wins,
  else the folder of that name. `/en/about-us/historie` works while
  `historie` has no English slug of its own.
- **The folder path stays the page's identity.** `page.urlPath`, `page()`,
  `Access:` inheritance, `access.public` globs, `options_from: pages` values,
  `/media` URLs and language-neutral redirect rules all use folder paths.
  `LanguageMiddleware` resolves `/en/about-us` to `/o-nas` before routing,
  so routes, access rules and actions see the folder path.
- **The folder slug redirects.** `/en/o-nas` answers `301` → `/en/about-us`
  (query kept), so links made before the slug was set keep working. Pages
  that are not live are not redirected: they return 404, so a hidden page
  doesn't reveal its slug.
- The default language has no translated slugs: its URLs are folder paths.
- Slugs are sanitized like folder names. Within one parent a slug must be
  unique per language: it can't match a sibling's folder or a sibling's
  slug in the same language. At the top level it can't be a language code.
  The admin refuses a clash (422). A hand-written duplicate goes to the first
  sibling in folder order.

## Visibility per translation

`Status`, `PublishAt`, `ExpireAt` and `PublishedAt` can differ per
translation. A translation without its own `Status` inherits the page's.
The admin always writes `Status` into a translation.

A page that exists in the default language but has **no translation file**:

| `languageFallback` | `/en/page` | in English listings, `translations()` |
|---|---|---|
| `404` (default) | 404 | left out |
| `content` | the default text (`page.translated` false) | included |

Translated sub-pages of an untranslated page still work. The parent acts
as structure: its status and `Access:` still apply to them.

Members-only access, inheritance and previews work the same in every
language, on unprefixed paths. A signed-in editor can preview a draft
translation at its URL.

## Templates

| | |
|---|---|
| `lang` | current language code (`''` on a one-language site) — `<html lang="{{ lang }}">` |
| `languages` | `[{code, label, locale, default, url, current}]`, where `url` is each language's home |
| `url(page)` / `url('/o-nas')` | public URL in the current language, translated slugs included (`/en/about-us`). A path that isn't a page keeps its ancestors' slugs (`/o-nas/kontakt/send` → `/en/about-us/kontakt/send`), as does a page without a translation. Left as they are: external and relative URLs, `#…` / `?…`, paths that already have a prefix, `/media`, `/thumb` and the admin — so editor-entered links can go through `url()` too. `url(page, 'en')` picks the language |
| `translations(page)` | languages the page is live in: `[{code, label, locale, default, url, current}]` |
| `t('key', {name: …}, 'fallback')` | site strings, see below |
| `page.lang`, `page.translated`, `page.translations` | the page's language, whether a translation exists, and its language codes |
| `page.publicPath`, `page.translatedSlug` | unprefixed path with translated slugs (`/about-us/team`); the translation's own `Slug:` or null |

`page()`, `child_pages()`, `nav_pages()` and `top_level_pages()` return pages
in the current language. **Links must use `url()`.** `page.urlPath` is the
folder path that identifies the page in every language.

```twig
<html lang="{{ lang }}">
{% for l in translations(page) %}
  <link rel="alternate" hreflang="{{ l.code }}" href="{{ app.baseUrl ~ l.url }}">
{% endfor %}

<nav>
  {% for p in nav_pages() %}<a href="{{ url(p) }}">{{ p.title }}</a>{% endfor %}
</nav>

<ul class="switcher">
  {% for l in translations(page) %}
    <li><a href="{{ l.url }}" {% if l.current %}aria-current="true"{% endif %}>{{ l.label }}</a></li>
  {% endfor %}
</ul>
```

### Site strings

Interface text that isn't content goes in `site/lang/<code>.yaml`. Override
the directory with `paths.lang`.

```yaml
# site/lang/en.yaml
nav:
  menu: Menu
greeting: Hello {name}
```

`{{ t('nav.menu') }}` and `{{ t('greeting', {name: user}) }}` look up the
current language, then the default language, then the third argument, then
the key itself. A one-language site reads `site/lang/default.yaml`.

`t('…')` (a function) is separate from the admin's `t.key` global.

## Admin

- The page edit form has **language tabs**. The default tab is the usual
  form. A translation tab (`?lang=en`) edits the title, metatitle, body,
  translatable page fields, display date, the translation's visibility and
  its **slug** (empty = the folder slug). Template, listing, cascade and
  `translate: false` fields stay on the default tab.
- An untranslated page opens with the default text and a notice. Saving it
  creates `page.en.txt`.
- **Delete translation** removes only that file. Deleting the page removes
  every translation with it.
- The page tree shows a badge per language. A dashed badge means not translated yet.

## Other parts of the system

- **Render cache.** Keys include the language and the translation's mtime.
- **Scheduling.** `VisibilityHorizon` watches the dates of every translation.
- **Redirects plugin.**
  - A rule without `lang` is language-neutral. It matches the folder path in
    every language: `/en/old` matches the rule for `/old`. A local target is
    a folder path; the visitor gets it in their language, translated slugs
    included (`/o-nas` → `/en/about-us`). External targets are left alone.
  - `lang: en` scopes a rule to English requests. Its `from` and `to` are
    public paths in that language (translated slugs, no prefix); the prefix
    is added to a local target. Such a rule can't be `force`. The admin rule
    form has a language select.
  - Renaming or moving a folder adds a neutral rule. It covers every
    language, because the folder moves with all its translations.
    Changing a translated slug adds a rule in that language:
    `/about-us/{path*}` → `/who-we-are/{path}` with `lang: en`.
  - The 404 log records the visited path with its prefix (`/en/x`), so misses
    per language stay apart. A rule created from such an entry gets `lang`.
  - The page panel and badges list neutral rules only.
- **`thumbs:warm`** fetches every live page in every language.
- **Events / task hooks.** `page.saved` from a translation tab carries `lang`
  (and `translation_created`). Deleting a translation fires
  `page.translation.deleted` with `lang`.
- **Site actions** can be posted to under a prefix (`/en/contact/send`).
  The language is then current for the handler. `$ctx->redirect('/contact')`
  goes to `/en/contact`, and `back()` keeps the referring URL. The request
  attributes are `station0.lang` and `station0.langPrefix`.
- **Members-only redirect.** An anonymous visitor of a gated `/en/…` page is
  sent to `access.redirect` in English (`/` → `/en`).
- All of these use `LanguageMiddleware::localize($request, $path)`. It leaves
  alone external URLs, paths that already carry a language prefix, and
  admin, `/media` and `/thumb`.

## Limits of this release

- Collections are single-language.
- Blocks are translated as a whole body. A `translate: false` field inside a
  block is not shared yet.
- `options_from: pages` stores folder paths and labels them with
  default-language titles.
- No `Accept-Language` redirect from `/`.

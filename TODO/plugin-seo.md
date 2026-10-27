---
title: SEO & AI-visibility plugin (`seo`) — meta, sitemap, robots, JSON-LD, llms.txt
status: open
priority: medium
area: plugin
created: 2026-10-27
related: [docs/plugins.md, docs/redirects.md, docs/visibility.md, src/Plugin/Registrar.php, src/Service/AccessPolicy.php, src/Service/ThumbService.php, TODO/content-redirects-on-delete.md]
---

## Problem

Station0 has almost no SEO support. What exists today:

- `Metatitle:` front matter (`Page::$metatitle`, `ContentRepository`), which
  the site template must use by hand.
- Correct status codes for non-live pages (404 / 410, `docs/visibility.md`)
  and `X-Robots-Tag: noindex` on previews (`PageController`).
- The redirects plugin (301s on rename / move).

Missing: meta description, canonical URLs, Open Graph / social cards,
`sitemap.xml`, `robots.txt`, structured data (JSON-LD), per-page `noindex`,
and anything for AI search / answer engines (`llms.txt`, Markdown versions of
pages, an AI crawler policy). Each site has to write all of this in its own
Twig layout, and most of it is easy to get wrong in the same ways: relative
canonical URLs, drafts or members-only pages in the sitemap, missing
`og:image` dimensions.

## Why it matters / why deferred

Every public site needs this, and AI answer engines (ChatGPT search,
Perplexity, Google AI Overviews, Claude) now send real traffic. They reward
the same things classic SEO does (crawlable server-rendered HTML, clean
structure, dates, authorship, structured data), plus a few new conventions.
This was deferred until the plugin API (0.10.0) existed. The redirects plugin
(0.10.1) proved it out, so this can now be built the same way.

## Decision: bundled optional plugin, plus a small core contract

**Recommendation: a bundled plugin `seo` (`Station0\Plugin\Seo\Plugin`),
enabled by default in the get-station0 skeleton config. Two small core
extension points go in first, because the plugin cannot be built cleanly
without them.**

Why not full core integration:

- Sites differ. A landing page, a members-only intranet (`access.mode =
  members`, where you want *no* indexing) and a blog need different defaults.
  "Not listed in config = not loaded" is the right switch.
- It is the largest optional feature so far: sitemap, JSON-LD vocabulary,
  AI conventions that are still changing (llms.txt), audits. Keeping it out of
  `src/Service/` keeps the core small, and it can change faster than the
  core release cycle.
- It is the second real user of the plugin API, so it tests that API the way
  redirects did, and shows where the API is missing pieces (see below).

Why not a pure plugin with no core changes:

- **Head output.** A plugin can add a Twig function, but a layout that calls
  `{{ seo_head() }}` stops compiling (unknown function) when the plugin is
  turned off. Core needs a stable slot that always exists.
- **Page fields.** `contentPanel` renders *outside* the page edit `<form>`,
  and plugins cannot add fields to it. SEO fields (description, OG image,
  noindex) belong in the page form, so they save with the page and get the
  page's media upload.

Already in core, keep it there: `Metatitle` (keep it working; the plugin
reads it), status codes and visibility, preview `noindex`, `AccessPolicy`.

### Core prerequisites (own PR, before the plugin)

1. **Head slot** — `Registrar::head(callable $fn)` →
   `fn(array $context): string` (context: `page`, `fields`, `visitor`,
   request path, status). Core Twig function `head_tags()` (always defined,
   empty without contributors) concatenates them in plugin order. The skeleton
   layout calls `{{ head_tags() }}` in `<head>`. Optional
   `Registrar::bodyEnd()` for the same reason (e.g. verification scripts),
   only if needed.
2. **Plugin page fields** — `Registrar::pageFields(string $target, string
   $section, array $schema, ?string $role)`: a dict-form schema (same format
   and types as `<template>.blocks.yaml` `fields`, `FieldSchema::normalize()`)
   rendered as its own collapsible section inside the page form (and the
   collection-item form for `collectionItem[:name]` targets), stored in front
   matter under a plugin prefix (`Seo_description`, `Seo_image`, …, through
   `PageFields`). Prefixed names cannot clash with template fields or
   `TemplateBlocks::RESERVED_FIELD_NAMES`. `page_fields()` /
   `fields` in Twig stay template-only; the plugin reads its own keys.
3. Optional: **`Registrar::publicFile(path, handler)`**, or just document that
   `publicRoutes` may claim root files (`/robots.txt`, `/sitemap.xml`,
   `/llms.txt`). Check that a physical `public/robots.txt` in the skeleton
   wins over the route (document it), and that `Rules` (redirects) treats
   these paths as reserved sources.

## Requirements

### 1. On-page metadata (head output)

- `<title>` from a pattern, option `title: "{title} · {site}"`. Placeholders
  via `Placeholders::fill()`. `Metatitle` / `Seo_title` overrides the page
  part; option `titleHome` for the root page.
- `<meta name="description">`: page field, else `Summary`, else the first
  ~155 characters of the rendered body text (strip tags, stop at a word
  boundary). Mark it as auto in the admin so editors see when they should
  write one.
- `<link rel="canonical">`: always absolute, from `baseUrl` (refuse to emit,
  and warn in the audit, when `baseUrl` is missing or `localhost` in
  production). Field override for syndicated content. One URL form only (no
  trailing slash, lower-case as stored). Query strings are dropped from the
  canonical.
- Robots meta: per-page `Seo_noindex` / `Seo_nofollow` (inherited by the
  subtree when `Seo_cascade` is set, like `Access:`); also sent as an
  `X-Robots-Tag` header via middleware. `max-image-preview:large` by default
  (needed for Discover / large previews). Automatic `noindex` for previews
  (exists), members-only pages, `loginPath`, action result pages, and 404/410.
- Open Graph + Twitter/X: `og:title`, `og:description`, `og:url` (=
  canonical), `og:type` (`website` / `article` by template mapping),
  `og:site_name`, `og:locale`, `og:image` + `og:image:width/height/alt`,
  `article:published_time` / `modified_time`, `twitter:card =
  summary_large_image`. Image: field → template mapping (e.g. `fields.hero`)
  → first image in the body → site default. Always go through
  `|thumb(1200, 630, 'cover')` so the size is known and the file is small.
  Never use members-only media (`AccessPolicy::mediaRequiresMember()`).
- `<html lang>` is the template's job, but expose `seo.locale` (option
  `locale`, default from `admin_locale`) for it to use.
- Verification tags from options (`google-site-verification`,
  `msvalidate.01`, `facebook-domain-verification`…), as a generic
  `meta: {name: content}` map.
- Optional extras: `theme-color`, favicon / `apple-touch-icon` links, RSS/Atom
  `<link rel="alternate">` when a feed exists (feeds are a separate TODO).
- Twig escape hatch: a `seo` global (resolved title, description, image,
  canonical, robots) for templates that want their own markup;
  `seo_head(false)` / option `head: false` turns automatic output off.

### 2. Sitemaps

- `/sitemap.xml`: a sitemap index when there are more than 50,000 URLs or
  10 MB, else a single urlset (in practice, one file for most sites).
- Includes only pages that are **live** (`isLive()`, no `blockingAncestor`),
  **public** (`AccessPolicy` for an anonymous visitor), not `noindex`, and not
  canonicalized elsewhere. `Listing: unlisted` / `nav-hidden` pages are
  *included* (they are reachable, just not in menus), with option
  `sitemapUnlisted: false`.
- `<lastmod>` = `Updated:` front matter, else the page file mtime (real
  content changes only, never "now"). No `priority` / `changefreq`: Google
  ignores them, so leave them out.
- Image entries (`image:image`) for block and markdown images. Optional, on
  by default.
- Cached in `FileCache` and flushed with it (page save, `cache:clear`,
  `VisibilityHorizon`, so scheduled publishing shows up on time). Served with
  `Last-Modified` / `ETag`, and `304` on conditional requests.
- Per-template exclusion (`sitemap: false` in plugin options by template),
  plus a hook for other plugins / site code to add URLs (collection-driven
  pages, action pages).
- `Sitemap:` line in robots.txt, and an optional ping/IndexNow on publish
  (see 6).

### 3. robots.txt and AI crawler policy

- Generated `/robots.txt`: `Disallow` for `adminPath`, `loginPath`,
  `/thumb/` (optional), action paths; the `Sitemap:` absolute URL; and
  `Disallow: /` for everything when `access.mode = members` or option
  `indexing: false` (staging). Staging safety: when `baseUrl` host looks like
  staging/dev/preview, or env `STATION0_NOINDEX=1`, send `noindex` everywhere
  and disallow everything.
- **AI policy as config, not hand-written lines.** Separate *training* from
  *search / answering*, because blocking a search bot hides the site from AI
  answers:

  ```php
  'ai' => [
      'search'   => true,    // OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User…
      'training' => false,   // GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider…
      'agents'   => [],      // explicit per-UA overrides: ['GPTBot' => 'allow']
  ],
  ```

  The user-agent list lives in one versioned PHP file in the plugin
  (it changes, so it is easy to update in a patch release), documented with
  sources.
- Content Signals line (`Content-Signal: search=yes, ai-input=yes,
  ai-train=no`) generated from the same config.
- **EU TDM opt-out (DSM Directive art. 4).** This matters for EU sites: when
  `training` is false, emit `<meta name="tdm-reservation" content="1">`
  and `/.well-known/tdmrep.json` (TDMRep), with an optional `tdm-policy` URL.
- Remember that robots.txt is a request, not enforcement. Say so in the docs,
  and point to server-level blocking for people who need it.

### 4. Structured data (JSON-LD)

- One `<script type="application/ld+json">` with an `@graph`, built from
  objects with stable `@id`s (`{baseUrl}/#website`, `#organization`,
  `{canonical}#webpage`) so the entities link together.
- Site-wide from options: `WebSite` (name, url, inLanguage; optional
  `SearchAction` if the site has search), `Organization` **or** `Person`
  (name, logo, `sameAs` social profiles, contact), optional `LocalBusiness`
  subtype (address, geo, openingHours, telephone).
- Per page: `WebPage` (or `AboutPage` / `ContactPage` / `CollectionPage` for
  streams), `BreadcrumbList` from the `Page::$parent` chain, `primaryImageOfPage`.
- **Template → type mapping** in options, with field mapping:

  ```php
  'schema' => [
      'article' => ['type' => 'BlogPosting', 'image' => 'hero', 'author' => 'author'],
      'product' => ['type' => 'Product', 'map' => ['offers.price' => 'price', 'offers.priceCurrency' => 'EUR']],
      'event'   => ['type' => 'Event', 'map' => ['startDate' => 'date', 'location.name' => 'venue']],
  ],
  ```

  Article-like types get `headline`, `datePublished` (`PublishedAt`),
  `dateModified` (`Updated` / mtime), `author` (`Author:` → `Person`, or a
  collection item via `options_from: collection:authors`), `publisher`
  (→ `#organization`), `image`.
- `FAQPage` / `HowTo` from optional block types (`faq` block schema in the
  skeleton). Google shows fewer FAQ rich results since 2023, but AI answer
  engines still use the question/answer structure.
- Escape hatch: a `Seo_jsonld` raw field (admin role only), plus a Twig hook
  `seo_schema(page)` so site templates can extend the graph.
- Output is validated in tests against the required properties of each
  emitted type. Values are escaped for `</script>` (`JSON_HEX_TAG`).

### 5. AI / answer-engine optimization (GEO)

- **`/llms.txt`** (llmstxt.org format): H1 site name, a `>` summary (option or
  root `Summary`), then sections of `- [Title](url): description` built from
  the nav tree / sections. Uses the same inclusion rules as the sitemap.
  Option `llms.sections` to choose and order subtrees. Optional
  **`/llms-full.txt`** with the full Markdown of included pages (size cap,
  cached).
- **Markdown version of every page**: `/{path}.md` (and/or content
  negotiation on `Accept: text/markdown`) serving the page as clean Markdown:
  front-matter title, summary, dates and canonical as a header, text blocks
  as stored Markdown, other blocks through an optional `markdown.twig` beside
  the block's `template.twig`, else stripped HTML → text. Advertised with
  `<link rel="alternate" type="text/markdown" href="…">`. Same access and
  visibility rules as HTML (404/410, members gate).
- Content signals that AI answers use, surfaced as audit checks rather than
  enforced: visible author and date, `dateModified` that changes only on real
  edits, one `<h1>`, descriptive headings, a short summary/answer at the top,
  alt text on images, no content hidden behind JS (Station0 renders on the
  server already, so say that in the docs).
- No `noai` / `noimageai` meta by default. They are not a standard and are
  mostly ignored; offer them only as part of `training: false`, labeled
  best-effort.
- Out of scope: tracking AI referrals or citations (analytics belongs to a
  separate plugin), and generating descriptions with an LLM (possible later as
  an opt-in admin button, never automatic).

### 6. Indexing signals & integrations

- **IndexNow** (Bing, Yandex, Seznam, Naver; option `indexnow: true`): key
  file at `/{key}.txt` (key in `writable/`, like `thumbs.key`). On
  `page.saved` / `page.moved` / `page.deleted` events for public live pages,
  submit the changed URLs, batched and in the background (`TaskLauncher` or a
  shutdown hook; never block the admin save). Scheduled publishing via
  `VisibilityHorizon` should submit too. Google does not support IndexNow, so
  the sitemap `lastmod` is its signal.
- **Redirects plugin**: a page moved with an auto rule drops out of the
  sitemap at its old URL and appears at the new one (automatic through the
  live check). The audit flags internal links pointing at redirected or 404
  URLs.
- Cache headers for crawl efficiency: `Last-Modified` on page responses where
  `PageRenderer` cache allows; 304 handling. Possibly a core item; decide
  during implementation.

### 7. Admin

- **SEO section in the page form** (via core prerequisite 2): title override
  (wraps `Metatitle`), description with a live length counter (title ~60 /
  description ~155 characters, shown as pixel-width guidance, not hard
  limits), social image (upload, previewed as a 1200×630 crop), noindex /
  nofollow, canonical override (editors see it read-only, admins can edit),
  schema type override.
- Live **SERP preview** and **social card preview** (from the same resolved
  values the head uses; one PHP resolver feeds both through a JSON endpoint,
  so the JS does not reimplement it).
- **Content panel / badge**: issue count (`⚠ 3`) in tree rows and stream
  records via `contentBadge`.
- **Site audit** at `/admin/seo` (role editor), console `seo:audit
  [--json]`, and a site task hook. Checks: missing / duplicate titles and
  descriptions, title or description too long or short, missing H1 or more
  than one, images without alt (blocks + markdown), broken internal links
  and links to redirected URLs, orphan pages (live, not linked from nav or
  any page), noindex pages in nav, large unthumbed images, missing
  `baseUrl`, sitemap size, JSON-LD missing required properties. Each issue
  links to the page edit form.
- Settings view showing the effective config (read-only; config stays in
  `site/config.php`, consistent with the rest of Station0).
- Strings in `src/Plugin/Seo/lang/{en,cs}.php`.

### 8. Console

`seo:audit`, `seo:sitemap [--write]` (prints, or writes a static
`public/sitemap.xml` for setups that serve files directly), `seo:llms
[--full] [--write]`, `seo:indexnow [path…|--all]`, `seo:robots`
(prints the effective file).

### 9. Non-functional

- No extra work on normal page requests beyond building the head (all of it
  comes from data `PageRenderer` already has). Sitemap / llms files are
  cached and flushed with `FileCache`.
- Correct with `access.mode = members`, per-page `Access:`, scheduled
  publishing, previews (never index a preview, never include gated content
  anywhere: sitemap, llms, Markdown, OG image, JSON-LD).
- Works when `baseUrl` has a subpath, or say clearly that it is unsupported.
- Multilingual (`hreflang`, per-language sitemaps): Station0 has no
  multi-language content model yet. Design the resolver so `alternates` can
  be added later; do not build it now.

## Proposed fix (phasing)

1. **Core PR** — head slot, plugin page fields, reserved root paths.
   Minor release. Skeleton layout gains `{{ head_tags() }}`.
2. **Plugin MVP** — head (title, description, canonical, robots, OG/Twitter),
   page form section, sitemap, robots.txt with AI policy, TDM. Enough to
   replace hand-written head markup on a real site. Skeleton enables `seo`.
3. **Structured data** — `@graph`, Organization/WebSite, BreadcrumbList,
   template mapping, Article/Product/Event, FAQ block.
4. **AI** — llms.txt, llms-full.txt, `.md` page versions, alternate links.
5. **Admin & signals** — SERP/social preview, audit view + console, badges,
   IndexNow.

Each phase is its own PR and release. Split phases 3–5 into their own TODO
files when phase 2 lands.

Pitfalls:

- Resolve everything in one `Seo\Resolver` (page → title, description, image,
  canonical, robots, graph). The head, admin preview, sitemap, llms and audit
  all use it, so they can never disagree.
- Never compute `lastmod` / `dateModified` from "now", or from a cache
  rebuild.
- Keep plugin front-matter keys prefixed. Read legacy `Metatitle` but do not
  rename it.
- `register()` stays declarative (no file I/O); the UA list and schema
  mapping are loaded lazily.

## Done when

- Core: `head_tags()` exists without plugins (empty string); `pageFields`
  renders inside the page form and round-trips through `FrontMatter`; tests
  in `tests/Plugin/` cover both.
- Plugin: `tests/Plugin/Seo/` covers head output (absolute canonical, OG
  image via thumb with dimensions, noindex inheritance), sitemap inclusion
  (draft / scheduled / expired / under a hidden parent / members-only /
  noindex all excluded; unlisted included; lastmod from `Updated`), robots.txt
  for public / members / staging / AI config, `tdmrep.json`, JSON-LD validity
  per type, llms.txt + `.md` routes respecting access, IndexNow batching on
  events (HTTP client faked), and audit checks on a fixture site.
- `docs/seo.md` reference; `docs/plugins.md` documents the new Registrar
  methods; CLAUDE.md gets a short `seo` section like the redirects one;
  `CHANGELOG.md` per release.
- Skeleton (get-station0): layout uses `{{ head_tags() }}`, config enables
  `seo` with commented options (site name, organization, AI policy), README
  mentions it.
- Validated on a real deployed page: Google Rich Results Test passes, Schema
  Markup Validator shows no errors, Facebook / LinkedIn card previews are
  correct, Search Console accepts the sitemap.

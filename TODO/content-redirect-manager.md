---
title: Redirect manager — auto-redirect on slug change, exact / pattern / regex rules, 404 log, zero cost on normal requests
status: open
priority: medium
area: content
created: 2026-10-26
related: [TODO/core-plugin-architecture.md, src/Controller/PageController.php (notFound), src/Controller/Admin/PageController.php (rename ~571, move ~208), src/Service/ContentRepository.php (rename, move), src/Controller/AssetController.php, src/Bootstrap.php (registerRoutes)]
---

## Problem

Renaming a page's slug or moving it in the Structure tree changes its URL
(`ContentRepository::rename()` / `move()`), and the old URL — plus every URL
of its subtree — becomes a 404. External links, bookmarks and search-engine
results break, and there is no way to set a redirect by hand at all: no exact
`/old → /new`, no patterns, no 410 for removed content, and no visibility
into which URLs visitors actually hit and miss.

## Why it matters

- SEO: a moved page loses its ranking without a 301.
- Editors rename slugs casually (typo fixes, restructuring) and don't know
  they broke links.
- Migrating an existing site to Station0 needs a bulk set of rules
  (`/index.php?id=12`, `/2019/05/post-name.html` …).
- **Performance is a hard constraint**: a page request that is not a redirect
  must not pay for the feature — no file read, no DB query, no regex loop.

## Proposed fix

Ship it as the first bundled plugin (`redirects`) on top of
`TODO/core-plugin-architecture.md`, off by default:

```php
// site/config.php
'plugins' => [
    'redirects' => [
        'auto'     => true,     // add a rule when a page's URL changes (default true)
        'log404'   => true,     // record misses for the admin 404 log (default true)
        'ignore404'=> ['/wp-*', '*.php', '/.well-known/*'],
        'maxAge'   => 3600,     // Cache-Control for 301/308 (browsers otherwise cache forever)
    ],
],
```

If the plugin API is not ready, the same design works as a core service behind
`'redirects' => ['enabled' => true]`; only the wiring differs.

### Rules file — `site/content/_redirects.yaml`

Content, not config: it travels with the content dir (backups, git), editors
change it through the admin, developers can hand-edit it. Same location
convention as `_groups.yaml`.

```yaml
- from: /old-page                    # exact
  to: /new-page                      # status defaults to 301
- from: /blog/{year}/{slug}          # pattern: {name} = one segment
  to: /articles/{slug}
- from: /docs/{path*}                # {name*} = zero or more segments (with slashes)
  to: https://docs.example.com/{path}
- from: '^/p/(\d+)$'                 # regex (explicit type), captures as $1…
  type: regex
  to: /products/$1
  status: 302
- from: /discontinued                # 410 Gone, no target
  status: 410
- from: /about-us
  to: /about
  force: true                        # applies even when /about-us exists as a page
- from: /team/{path*}                # written automatically on a slug change
  to: /about/team/{path}
  auto: 2026-10-26
  note: Renamed in admin by lexa
```

- `type` is inferred: `{…}` in `from` ⇒ `pattern`, else `exact`; `regex` must be
  explicit (no guessing from delimiters).
- `status`: 301 (default), 302, 307, 308, 410.
- Query string is ignored when matching and appended to the target by default
  (`query: drop` to discard). Matching normalizes the trailing slash; case-
  sensitive by default (`caseless: true` per rule).
- First match wins in file order; exact rules always before pattern/regex.

### Performance design (the important part)

1. **Fallback by default.** Rules are consulted only when the request is
   already going to be a 404/410 — in `PageController::notFound()` and the
   `/media` 404 branch in `AssetController`. A request that finds a page never
   touches the redirect code. This also makes the 404 path *cheaper* than today
   when a rule matches (no Twig render).
2. **Compiled rule set.** `_redirects.yaml` is compiled to
   `writable/cache/redirects.php` returning a plain array (served from opcache,
   microseconds to `require`):
   - `exact` → hash map `path => [to, status, …]` — one `isset()`;
   - patterns + regexes → **one combined regex** per group using `(*MARK:n)`
     alternation (FastRoute's mark-based approach), so N rules = one
     `preg_match`, not N;
   - `force` rules compiled separately, plus a `hasForce` flag.
   Recompiled when the YAML's mtime is newer than the compiled file's (stat
   only on the 404 path) and immediately after every admin write.
3. **Force rules cost only if used.** A `RedirectMiddleware` (outside routing,
   GET/HEAD only) is registered at boot **only when the compiled set has
   `force` rules**; then it costs one `isset()` (+ one `preg_match` if there
   are force patterns) per request. No force rules ⇒ no middleware.
4. **Plugin off ⇒ nothing loaded** (no routes, middleware, services).
5. **Hit counters are opt-in** (`count: true`) — a write per redirected
   request is not free. The 404 log writes only on 404 responses (see below).

Budget to verify with a benchmark test: 10 000 exact + 500 pattern rules,
non-matching path resolved in < 0.2 ms; a normal page request shows no
measurable difference with the plugin on vs. off.

### Auto-redirect on URL change

Listen to the existing events (`page.saved` with `previous_path`,
`page.moved`) — through the plugin event dispatcher, not `TaskHooks`.

- One rule per change covers the whole subtree:
  `from: /old/{path*}` → `to: /new/{path}` with `auto:` date.
- **Flatten chains:** existing rules whose target starts with the old path are
  rewritten to the new path (A→B then B→C leaves A→C and B→C, never a chain).
- **Undo:** renaming back (B→A) removes the rule whose `from` now matches a
  live page again — fallback rules are shadowed by real pages anyway, but the
  file should not collect dead rules.
- Creating a page at a path a rule redirects from: the page wins (fallback
  semantics); the admin rule list marks the rule "shadowed by a page".
- Gap to document: renames done by site tasks via the repositories fire no
  events, so they add no redirect (same rule as task hooks). A task can call
  the plugin's `Redirects::add()` itself.
- Page delete: optional follow-up — offer "redirect to parent / 410" in the
  delete confirm. Not in the first PR.
- Media: the subtree rule also answers `/media/old/...` → `/media/new/...`
  (assets travel with the page dir). Thumbnails are not redirected — the
  signature covers the path, the page re-renders new thumb URLs anyway.

### 404 catch / log

- SQLite table `station0_404` (`path` PK, `hits`, `first_seen`, `last_seen`,
  `referrer`) written with one UPSERT on a 404 that no rule matched.
- `ignore404` globs skip bot noise; cap the table (e.g. 5 000 rows, prune
  oldest `last_seen`) and store paths truncated to 512 chars, no query string
  (no PII from query params).
- Admin: "404 log" view sorted by hits, button "Create redirect" prefilled
  with the path, "Ignore" adds to an ignore list, "Clear".

### Admin

- "Redirects" menu tab (plugin nav item), role `admin`; editors see and add
  exact rules only (pattern/regex need admin — they can redirect broadly).
- List with type, from, to, status, auto/manual, shadowed / broken-target
  warnings (target is an internal path that does not resolve to a live page).
- Add/edit form with live validation; **"Test a URL"** box shows which rule
  matches and the resulting target.
- Writes go through `RedirectStore` (flock + tmp file + rename, then
  recompile), never by string-editing the YAML.

### Validation & safety

- `from` must be a local path; reject sources under `adminPath`, `/thumb`,
  `access.loginPath` and site action paths.
- Regex: compile-check on save (`preg_match` with error handling), anchor
  required (`^…$`), run with a lowered `pcre.backtrack_limit` during matching
  to contain catastrophic patterns.
- Targets: local path or absolute `http(s)` URL. Captures/placeholders may
  only be substituted into the **path/query** part of a target, never into
  scheme or host (prevents an open redirect such as `to: https://$1`). A
  local target is normalized so `//evil.com` can't sneak through.
- Loop check on save: following rules from the target must not return to the
  source (bounded walk, e.g. 10 hops).
- Redirect responses: `Location` + `Cache-Control: max-age={maxAge}` for
  permanent codes, `no-store` for 302/307.

### Console

`redirects:list`, `redirects:test <path>`, `redirects:add <from> <to> [--status=]`,
`redirects:import <file.csv>` (from,to,status — for site migrations),
`redirects:compile`, `redirects:404 [--clear]`.

## Done when

- Plugin enabled in config ⇒ rules from `_redirects.yaml` work for exact,
  `{name}` / `{name*}` patterns, regex and 410; disabled ⇒ no routes,
  middleware or files touched (test asserts the container has no redirect
  services and the middleware stack is unchanged).
- Renaming a slug and moving a page in the admin each add one subtree rule;
  chains are flattened; renaming back removes the rule. Unit tests for
  `RedirectStore` flattening and `RedirectMatcher` (all types, query handling,
  trailing slash, MARK compilation, force vs. fallback).
- Validation tests: open-redirect via captures rejected, reserved sources
  rejected, loops rejected, bad regex rejected with a readable error.
- 404 log records, ignores, caps and prunes; "Create redirect" from the log.
- Benchmark test (skipped in normal runs, `--group bench`) meets the budget
  above.
- `docs/redirects.md` + a CLAUDE.md section; CHANGELOG entry.

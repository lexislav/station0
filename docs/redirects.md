# Redirects

The redirect manager is a plugin bundled with station0 ≥ 0.10.1. It is off
by default. It does three things:

- It adds a redirect automatically when an editor renames a page's slug or
  moves it in the Structure tree. The rule covers the page's whole subtree,
  so old links and search results keep working.
- It answers redirect rules you write: exact paths, patterns, regexes and
  `410 Gone`.
- It keeps a log of the URLs visitors asked for that don't exist (404s).

```php
// site/config.php
'plugins' => [
    'redirects' => [
        'auto'      => true,     // add a rule when a page's URL changes
        'log404'    => true,     // record misses for the admin 404 log
        'ignore404' => ['/wp-*', '*.php', '/.well-known/*', '/favicon.ico', '/apple-touch-icon*'],
        'maxAge'    => 3600,     // Cache-Control max-age of 301 / 308 responses
        'logLimit'  => 5000,     // rows kept in the 404 log
    ],
],
```

All options are optional; the values above are the defaults. `'redirects'
=> []` turns the plugin on with them.

## Cost

A request for a page that exists does not pay for the plugin. It reads no
rules file, runs no regex and opens no database. The rules are consulted
only when a request is already going to be a 404 or 410.

- `site/content/_redirects.yaml` is compiled to `writable/cache/redirects.php`,
  a plain PHP array that opcache serves.
- An exact rule is one hash lookup.
- All pattern and regex rules are combined into one regex with `(*MARK)`
  alternatives, so a missing path costs at most one `preg_match`. With
  10 000 exact rules and 500 patterns, a lookup for a path that matches
  nothing takes a few microseconds (`vendor/bin/phpunit --group bench`).
- `force` rules (below) are the exception. Their middleware is added only
  while at least one force rule exists. It reads a small separate file,
  `redirects.force.php`, and costs one hash lookup per request, plus one
  `preg_match` if there are force patterns.

The compiled file is rebuilt after every change in the admin or console.
When you edit the YAML by hand, it is rebuilt on the next 404, because the
YAML is then newer than the compiled file, or when you run
`console redirects:compile`.

## Rules file — `site/content/_redirects.yaml`

The rules file is content, not config. It sits beside `_groups.yaml`, goes
into backups and git with the content, and editors change it through the
admin.

```yaml
- from: /old-page                    # exact
  to: /new-page                      # status defaults to 301
- from: /blog/{year}/{slug}          # pattern: {name} = one segment
  to: /articles/{slug}
- from: /docs/{path*}                # {name*} = zero or more segments
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
  auto: '2026-10-26'
  note: Renamed in admin by lexa@example.com
```

| Key | Meaning |
|---|---|
| `from` | Source path. A `{…}` in it makes it a pattern; otherwise the rule is exact. |
| `type` | Only `regex` needs to be written. A regex must be anchored (`^…$`) and can't use named groups. |
| `to` | A local path (`/…`) or an `http(s)://` URL. `{name}` and `$1` take values from the source. |
| `status` | `301` (default), `302`, `307`, `308`, or `410` (no `to`). |
| `force` | `true`: redirect even when a live page exists at `from`. |
| `caseless` | `true`: match regardless of letter case. Matching is case-sensitive by default. |
| `query` | `drop`: don't append the request's query string to the target. By default it is appended. |
| `count` | `true`: count hits (shown in the admin). This writes to SQLite once per redirect, so it is opt-in. |
| `auto`, `note` | Information only. `auto` is the date of an automatic rule. |

**Matching.** The query string is ignored when matching, so `/index.php?id=12`
and `/index.php?id=13` are the same source. A trailing slash is ignored too.
Exact rules are checked before patterns and regexes. Within each group, the
first rule in the file wins.

**Fallback.** A rule applies only where no live page answers. Draft,
scheduled and expired pages count as missing. A live page at `from`
**shadows** the rule, and the admin list marks such rules. Use `force` to
redirect away from an existing page.

**Writes.** The admin and console rewrite the file, so comments in it are
lost. Rules they can't parse are kept as written, moved to the end of the
file, and listed in the admin. If the file is not valid YAML, no rules
apply and nothing writes to it until you fix it. The error is in
`writable/logs/app.log`.

### Safety checks

Every rule is validated when it is saved.

- **Sources.** `from` must be a local path. It can't be under the admin
  path, `/thumb`, the member sign-in path or a site action's path.
- **Regexes.** A regex must be anchored and must compile. Matching runs with
  a lowered `pcre.backtrack_limit`, so a pathological pattern can't hang a
  request.
- **Targets.** Captured values are URL-encoded and only go into the path or
  query of a target, never into its scheme or host. That means
  `to: https://{host}/` and `to: //{x}` are rejected, and a local target is
  normalized so `//evil.com` can't appear in it.
- **Loops.** A rule whose target leads back to its source within 10 hops is
  rejected.

### Responses

- **301 and 308** send `Location` and `Cache-Control: public, max-age={maxAge}`.
  Browsers otherwise cache permanent redirects forever.
- **302 and 307** send `Location` and `Cache-Control: no-store`.
- **410** renders the site's `410.twig` (or `404.twig`) with status 410.

## Automatic rules

The plugin listens to the admin's `page.saved` event when it carries
`previous_path` (a slug change) and to `page.moved` (drag and drop).

- **One rule per change covers the subtree.** `/old/{path*}` → `/new/{path}`
  redirects the old page URL and every URL below it.
- **Chains are flattened.** Rules whose target pointed into the old location
  are rewritten to the new one. A→B followed by B→C leaves A→C and B→C, not a
  chain.
- **Renaming back** (B→A) removes the rules from A, because a live page
  answers there again. It adds B→A.
- **Media follow their page.** `/media/old/photo.jpg` redirects to
  `/media/new/photo.jpg`, because assets move with the page folder.
  Thumbnails are not redirected: their signature covers the path, and the
  page renders new thumbnail URLs anyway.
- **Not covered: changes made outside the admin.** Renames done by site
  tasks through the repositories fire no events, so they add no rule, the
  same as for task hooks. A task can add the rule itself:

  ```php
  \Station0\Plugin\Redirects\Store::forConfig($task->config())
      ->recordMove('/old-path', '/new-path', 'Renamed by the import task');
  ```
- `'auto' => false` turns this off.

## Admin

The admin has a **Redirects** menu tab (`{adminPath}/redirects`) for admins
and editors.

- **Rules.** The tab lists every rule with its type, status and flags. It
  warns when a rule is shadowed or its target is not a live page, and shows
  hit counts for counted rules. Rules can be added, edited and deleted here.
- **Test a URL.** Shows which rule answers a URL and where it leads,
  including the query string.
- **404 log** (`/redirects/404`). Misses with hits, first and last time
  seen and the referrer, 100 per page. Sorted by hits by default; click a
  column header (path, hits, first / last seen) to sort by it. Filter by
  path, by referrer (`-` = no referrer), by minimum hits and by how recently
  the path was seen. Each path gets a **suggested target** when one is
  likely (see below). **Redirect** adds a 301 to the suggestion right away;
  **Create redirect** opens the form with the path and the suggestion filled
  in. Either way you come back to the log with your filters kept. **Ignore**
  hides a path; tick several rows to ignore them at once (or, in the ignored
  view, to show them again). **Clear** (admins only) empties the log.
- **Roles.** Editors can add, edit and delete exact rules. Patterns, regexes
  and force rules redirect broadly or hide pages, so only admins can manage
  them.

### On the page itself

The page edit form gets a **Redirects** panel. It shows:

- the old URLs that lead to the page: exact rules, rules with this page as a
  static target, and the automatic subtree rules that cover it (shown as
  "/old-section/… via /old-section/{path*}")
- a warning when a force rule makes the page unreachable
- the fallback rules the page shadows
- **Add old URL**, which creates an exact 301 from a typed path to this page

The panel saves through the plugin's own admin routes, not through the page
form. A new page shows "Available after the first save".

In the Structure tree, a badge shows how many redirects lead to each page
(`↪ 2`). It turns red (`↪ ✕`) when a force rule blocks the page.

Rules with captured targets (`/articles/{slug}`, `$1`) can't be attributed to
a single page. They appear only in the Redirects tab.

## 404 log

Misses are stored in SQLite, in the `station0_404` table. This happens only
for GET/HEAD requests that end in a 404 and that no rule answered.

- The path is stored without its query string and cut to 512 characters.
  The referrer is stored without its query string.
- `ignore404` globs are never logged.
- Opening the log drops the paths that something now answers: a rule
  (from the admin, the YAML file, the console or an automatic one) or a
  live page created at that path. Ignored paths are dropped too.
- **Suggested target**, in this order: a live page whose last segment is
  the same (case-insensitive, a legacy `.html` / `.php` / `.aspx` extension
  stripped), the closest one if there are several; else a live page whose
  path is a few typos away (edit distance up to about a fifth of the path);
  else the nearest live ancestor. The homepage is never suggested, since
  sending misses there is a soft 404.
- The table keeps the `logLimit` most recently seen paths; older rows are
  pruned.

## Console

```bash
php vendor/bin/console redirects:list
php vendor/bin/console redirects:test '/old/url?x=1'
php vendor/bin/console redirects:add /old /new [--status=302] [--force] [--regex]
php vendor/bin/console redirects:import rules.csv     # from,to,status per line (header optional)
php vendor/bin/console redirects:compile
php vendor/bin/console redirects:404 [--limit=50] [--clear]
```

`redirects:import` is meant for migrating an existing site. Rows whose source
already has a rule, and invalid rows, are reported and skipped.

## Files

| Path | What |
|---|---|
| `site/content/_redirects.yaml` | the rules |
| `writable/cache/redirects.php`, `redirects.force.php` | compiled rules (generated) |
| site database (`writable/db.sqlite`, set by `paths.db`) → `station0_404`, `station0_redirect_hits` | 404 log, hit counters |

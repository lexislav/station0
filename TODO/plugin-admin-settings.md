---
title: Plugin admin layer — schema-driven settings page, form helpers, menu placement, plugin kinds
status: open
priority: medium
area: plugin
created: 2026-10-27
related: [docs/plugins.md, src/Plugin/Registrar.php, src/Plugin/Plugins.php, src/Plugin/TwigExtension.php, admin/templates/pages/_fields.twig, src/Service/FieldOptions.php, TODO/plugin-seo.md]
---

## Problem

Plugins can already add admin pages (`adminRoutes` + `templates` + `adminNav`
+ `translations`, templates extend `@admin/layout.twig`; see the redirects
plugin), but every screen is hand-written. For editor-facing ("user") plugins
the API is missing:

1. **Settings from a schema.** Plugin options come only from `site/config.php`
   (`BasePlugin::defaults()` / `config()`); editors cannot change them in the
   admin. SEO defaults, analytics IDs, banner texts… all need this.
2. **Reusable form helpers.** No supported way to render fields with
   `pages/_fields.twig`, resolve selects via `FieldOptions`, use the upload
   endpoints, or follow the 422 + `errorKey` pattern from a plugin page —
   the redirects plugin rebuilt its own forms.
3. **Menu placement.** `adminNav` only adds top-level tabs; no way to put an
   item under a "Settings" / "Plugins" section or into a `NavGroups` group.
   Many plugins would crowd the menu.
4. **Plugin kinds.** All plugins are equal and developer-enabled in config.
   No admin overview of enabled plugins, no distinction between system
   plugins (redirects, sitemap — config only) and feature plugins
   (editor-visible, with settings).
5. **Site-local plugins.** Only bundled or Composer classes (FQCN). No
   `site/plugins/` convention analogous to `site/tasks/` / `site/actions/`.

## Why it matters / why deferred

The plugin API is experimental (v0.10.0) and the first plugin (redirects) is a
system plugin that didn't need settings. The planned `seo` plugin
(`TODO/plugin-seo.md`) is the first that will — design it against that use
case rather than in the abstract.

## Proposed fix

- `Registrar::settings(array $fields, ?string $role = 'admin')` — dict-form
  field schema (same format as block schemas, `FieldSchema::normalize()`).
  Core provides the route (`{adminPath}/{name}/settings`), the form (shared
  `_fields.twig` + `collectFields()` JS), sanitizing (reuse `PageFields`
  per-type logic), and storage in `site/content/_plugins/<name>.yaml`
  (flock + tmp/rename like `Redirects\Store`).
- Effective options = `defaults()` ← `site/config.php` ← stored settings;
  decide whether config keys can be locked (config wins, field shown read-only).
  `BasePlugin::config()` reads the merged result; `configure()` validation
  must also run on admin input (→ 422).
- Expose a small helper for plugin pages: render a field set + parse/validate
  posted values, so custom plugin screens get the same widgets.
- `adminNav(..., section: 'settings'|'main'|group id)`; an admin "Plugins"
  page listing enabled plugins (name, version from Composer, kind, link to
  settings). Optional `Plugin::kind()` (`system` default | `feature`).
- Site-local plugins: evaluate `site/plugins/<Name>/Plugin.php` autoloaded
  under a site namespace vs. documenting PSR-4 in the skeleton's
  `composer.json`. Keep "unlisted plugin is never loaded" either way.
- Cache: saving settings flushes `FileCache` (settings may affect rendering).

## Done when

- A plugin declaring `settings()` gets a working admin settings page with
  role check, CSRF, validation errors and persisted values; covered by a
  `tests/Plugin/` app test (save → reboot → `config()` returns new value).
- Menu placement option + plugins overview page exist and are tested.
- Decision on site-local plugins recorded (implemented or documented).
- `docs/plugins.md` and CLAUDE.md "Plugins" section updated; the `seo` plugin
  (or the docs example plugin) uses `settings()`.

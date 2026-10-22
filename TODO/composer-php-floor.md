---
title: composer.json claims PHP ^8.2, but dependencies need 8.4.1
status: open
priority: low
area: console
created: 2026-10-22
related: [composer.json, CLAUDE.md]
---

## Problem
`composer.json` requires `php: ^8.2` and CLAUDE.md says "PHP ≥ 8.2", but
`symfony/yaml` ^8 requires `php >=8.4.1`. So the real minimum is 8.4.1:
installing on 8.2 or 8.3 fails at dependency resolution, not with a clear
platform error from station0.

## Why it matters / why deferred
The docs are misleading, but nothing breaks at runtime. This was noticed
during the 0.9.1 dependency bump and was not in scope there.

## Proposed fix
Raise the requirement to `php: ^8.4.1` (or `>=8.4.1`) and update CLAUDE.md
and the skeleton README. Either do it in a minor release, or note it in the
CHANGELOG as a documentation fix, since it is already the effective floor.

## Done when
`composer.json`, CLAUDE.md and the skeleton docs all state the same minimum
that `composer install` enforces.

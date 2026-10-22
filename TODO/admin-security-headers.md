---
title: Admin responses send no anti-framing / security headers
status: open
priority: low
area: admin
created: 2026-10-22
related: [src/Bootstrap.php]
---

## Problem
Admin pages are sent without `X-Frame-Options` or
`Content-Security-Policy: frame-ancestors`, so another site can frame the
admin (clickjacking). There is also no `Referrer-Policy`. The public site is
left to the skeleton or web server.

## Why it matters / why deferred
Clickjacking needs a signed-in editor to interact with a hostile page. CSRF
already protects form posts, but a framed click still counts. This is
low-risk hardening found in the 0.9.1 audit.

## Proposed fix
Add a small middleware on the admin group that sets
`X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`
and `Referrer-Policy: same-origin`. Consider an opt-in config for the same
headers on public routes.

## Done when
Every `/admin/*` response carries the headers, including login and setup.
A test or console check confirms it.

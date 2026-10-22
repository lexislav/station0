---
title: A .txt upload can replace a page's content source
status: open
priority: medium
area: media
created: 2026-10-22
related: [src/Service/MediaService.php, src/Service/ContentRepository.php]
---

## Problem
`txt` is on `MediaService::DOCUMENT_EXT`, so editors can upload `notes.txt`
into a page folder. `ContentRepository::findFileBySlug()` takes the first
`*.txt` from `glob()` (alphabetical), so an uploaded `a.txt` beside
`page.txt` becomes the page's content file: the page renders the upload and
switches template to `a`. The root folder (`pages/*.txt`) has the same problem.
`resolveAsset()` only blocks the page's *own* source file, so the real
`page.txt` can then be downloaded through `/media` with all its front matter.

## Why it matters / why deferred
Only admins and editors can upload, and they can already edit page content,
so this does not give anyone new rights. It does break pages by accident and
expose front matter. It was found during the 0.9.1 security audit and left
out of that patch, because the fix changes which files can be uploaded.

## Proposed fix
One of:
- reject `.txt` uploads into page folders (keep them for collection items,
  whose source is always `item.txt`), or
- store uploaded `.txt` under another name, or make `findFileBySlug()` prefer
  `<Template>.txt` / the file with front matter instead of the first glob hit.

## Done when
An uploaded `.txt` can never become a page's content file. A test in
`MediaServiceUploadTest` covers it.

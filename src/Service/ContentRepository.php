<?php

declare(strict_types=1);

namespace Station0\Service;

use Psr\Log\LoggerInterface;
use Station0\Support\FrontMatter;
use Station0\Support\Slug;
use Station0\Support\Visibility;

/**
 * Manages hierarchical flat-file content stored as .txt files.
 *
 * File format (Kirby-style front matter):
 *   Title: My Page
 *   Metatitle: SEO title (optional, falls back to Title)
 *   Template: article (optional – also derivable from filename)
 *   Status: published        (draft | published | archived; legacy `Published: true|false`)
 *   PublishAt: 2026-05-12 08:00   (optional: scheduled until then)
 *   ExpireAt: 2026-06-30 23:59    (optional: expired / 410 from then on)
 *   Listing: nav-hidden      (optional: listed | nav-hidden | unlisted)
 *   Cascade: false           (optional: when not live, hide only this page, not its subpages)
 *   Author: lexislav
 *   Updated: 2026-04-20 21:00:00
 *   ---
 *
 *   Body in **Markdown**.
 *
 * Directory structure:
 *   content/pages/
 *     page.txt               → URL: /         (root page, named after template)
 *     about/
 *       page.txt             → URL: /about
 *       team/
 *         page.txt           → URL: /about/team
 *     blog/
 *       article.txt          → URL: /blog      (different template)
 *       first-post/
 *         article.txt        → URL: /blog/first-post
 *
 * Slug  = directory name (never stored in the file)
 * Template = filename without .txt (authoritative; front-matter Template: is kept for readability)
 *
 * A directory should hold one content file. When it holds several `*.txt`
 * (e.g. a stray upload), contentFileIn() picks the one named after a template.
 *
 * Translations (multilingual sites, see Languages): `page.en.txt` next to
 * `page.txt` holds the English version of the page's text. It is never a
 * content file of its own; buildPage() merges it over the default file for
 * the repository's language — translated keys win, the rest falls back,
 * SHARED_KEYS (structure) always come from the default file.
 */
final class ContentRepository
{
    /** Front-matter keys mapped to Page properties (everything else lands in Page::$extra). */
    public const RESERVED_KEYS = [
        'title', 'metatitle', 'published', 'publishedat', 'author', 'updated', 'template', 'sort',
        'allowedchildtemplates', 'group', 'status', 'publishat', 'expireat', 'listing', 'cascade',
    ];

    /**
     * Keys that describe the page's place in the tree, not its text: always
     * read from the default-language file, never from a translation.
     */
    public const SHARED_KEYS = [
        'template', 'sort', 'allowedchildtemplates', 'group', 'listing', 'cascade', 'access',
    ];

    private array $parsedCache = [];

    /** Fixed language of this instance (see in()); null = the request's current language. */
    private ?string $lang = null;

    /** Untranslated pages serve the default text even in '404' fallback mode (admin editing). */
    private bool $forceFallback = false;

    /** @var array<string, self> */
    private array $languageViews = [];

    /**
     * Languages each content file exists in (default + translation files),
     * probed once per request. Shared with the in() views, like $parsedCache.
     *
     * @var array<string, list<string>>
     */
    private array $translationsOf = [];

    /** @var array<string, true> directories already reported as ambiguous */
    private array $warnedDirs = [];

    /**
     * @param ?string $templatesDir site templates; lets contentFileIn() tell the
     *                              content file (`<template>.txt`) from other `.txt` files
     */
    public function __construct(
        private readonly string $pagesDir,
        private readonly ?string $templatesDir = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Languages $languages = null,
        /**
         * Page fields of a template that are shared across languages
         * (`translate: false`), lower-cased; never read from a translation.
         *
         * @var (\Closure(string): list<string>)|null
         */
        private readonly ?\Closure $sharedFields = null,
    ) {
        if (!is_dir($this->pagesDir)) {
            @mkdir($this->pagesDir, 0775, true);
        }
    }

    // ─────────────────── Languages ───────────────────

    /**
     * This repository in a fixed language ('' = the default one). With
     * $withFallback, pages without a translation are returned with the
     * default text (Page::$translated false) instead of being left out —
     * what the admin edits. Not multilingual ⇒ $this.
     */
    public function in(string $lang, bool $withFallback = false): self
    {
        if ($this->languages === null || !$this->languages->enabled()) {
            return $this;
        }
        $lang = $this->languages->has($lang) ? $lang : $this->languages->default();
        $key  = $lang . ($withFallback ? '+' : '');
        if (!isset($this->languageViews[$key])) {
            $view = clone $this;
            $view->lang          = $lang;
            $view->forceFallback = $withFallback;
            $view->languageViews = [];
            // One parse cache for every view: a write through any of them
            // (or the base) is seen by all.
            $view->parsedCache    = &$this->parsedCache;
            $view->warnedDirs     = &$this->warnedDirs;
            $view->translationsOf = &$this->translationsOf;
            $this->languageViews[$key] = $view;
        }
        return $this->languageViews[$key];
    }

    /** Language this repository reads in; '' when the site is not multilingual. */
    public function language(): string
    {
        if ($this->languages === null || !$this->languages->enabled()) {
            return '';
        }
        return $this->lang ?? $this->languages->current();
    }

    /** True when reading a non-default language. */
    private function translating(): bool
    {
        $lang = $this->language();
        return $lang !== '' && !$this->languages->isDefault($lang);
    }

    /** Untranslated pages are left out (404, not listed) in this language. */
    private function hidesUntranslated(): bool
    {
        return $this->translating() && !$this->forceFallback
            && $this->languages->fallback() === Languages::FALLBACK_404;
    }

    // ─────────────────── Public API ───────────────────

    /**
     * Find a page by its URL path (e.g. "/about/team").
     */
    public function find(string $urlPath): ?Page
    {
        $urlPath = $this->normalizePath($urlPath);

        if ($urlPath === '/') {
            $root = $this->findRoot($this->pagesDir, '/');
            return $root !== null && $this->hidesUntranslated() && !$root->translated ? null : $root;
        }

        $segments = array_values(array_filter(explode('/', $urlPath)));
        foreach ($segments as $segment) {
            // Segments become directory names: `.` / `..` (Slim URL-decodes
            // `%2e%2e`), backslashes and control bytes must never reach the
            // filesystem, or a lookup — and delete() — escapes the pages tree.
            if ($segment === '.' || $segment === '..' || str_contains($segment, '\\')
                || preg_match('/[\x00-\x1F]/', $segment)) {
                return null;
            }
        }
        $page = $this->findBySegments($this->pagesDir, $segments, '');
        return $page !== null && $this->hidesUntranslated() && !$page->translated ? null : $page;
    }

    /**
     * Return ALL pages as a flat list, depth-first in sibling sort order.
     * $includeUnpublished = false keeps only live pages (own state live and
     * every ancestor live — see Page::isLive()).
     *
     * @return list<Page>
     */
    public function all(bool $includeUnpublished = true): array
    {
        $pages = $this->scanDir($this->pagesDir, '', true);

        // An untranslated page does not exist in this language ('404' mode);
        // its translated sub-pages still do.
        if ($this->hidesUntranslated()) {
            $pages = array_values(array_filter($pages, fn (Page $p) => $p->translated));
        }

        if (!$includeUnpublished) {
            $pages = array_values(array_filter($pages, fn (Page $p) => $p->isLive()));
        }

        return $pages;
    }

    /**
     * Live direct children of $parentUrl, in sort order — a content listing:
     * nav-hidden pages are included, unlisted ones only on request.
     *
     * @return list<Page>
     */
    public function children(string $parentUrl, bool $includeUnlisted = false): array
    {
        $parentUrl = $this->normalizePath($parentUrl);
        return array_values(array_filter(
            $this->all(false),
            fn (Page $p): bool => $p->urlPath !== '/'
                && (rtrim(dirname($p->urlPath), '/') ?: '/') === $parentUrl
                && ($includeUnlisted || $p->isListed()),
        ));
    }

    /**
     * Live direct children of $parentUrl that belong in navigation
     * (Listing: listed).
     *
     * @return list<Page>
     */
    public function navChildren(string $parentUrl = '/'): array
    {
        return array_values(array_filter($this->children($parentUrl), fn (Page $p): bool => $p->inNav()));
    }

    /**
     * Save a page. Pass $targetFilePath when creating a new file.
     * For updates, $page->filePath is used.
     */
    public function save(Page $page, ?string $targetFilePath = null): void
    {
        // A page read in another language saves its translation file only.
        if ($page->lang !== '' && $this->languages !== null && !$this->languages->isDefault($page->lang)) {
            $this->saveTranslation($page);
            return;
        }

        $page->updated = date('Y-m-d H:i:s');
        $filePath = $targetFilePath ?? $page->filePath;

        if (!$filePath) {
            throw new \RuntimeException('No filePath for save.');
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $content = $this->serialize($page);
        $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        rename($tmp, $filePath);

        unset($this->parsedCache[$filePath]);
    }

    /**
     * Write the page's text in its language ($page->lang) to the translation
     * file next to its content file: title, metatitle, visibility (status +
     * dates), body and the translatable page fields. Structure (SHARED_KEYS)
     * and `translate: false` fields stay in the default file.
     */
    public function saveTranslation(Page $page): void
    {
        if ($page->filePath === '' || $page->lang === '' || $this->languages === null
            || $this->languages->isDefault($page->lang)) {
            throw new \RuntimeException('Not a translation.');
        }
        $page->updated = date('Y-m-d H:i:s');
        $file = Languages::translationFile($page->filePath, $page->lang);

        $shared = array_flip([...self::SHARED_KEYS, ...$this->sharedFieldsOf($page->template)]);
        $fields = [
            'Title'       => $page->title,
            'Metatitle'   => $page->metatitle,
            'Status'      => $page->status,
            'Published'   => $page->isPublished() ? 'true' : 'false',
            'PublishAt'   => $page->publishAt,
            'ExpireAt'    => $page->expireAt,
            'PublishedAt' => $page->publishedAt,
            'Author'      => $page->author,
            'Updated'     => $page->updated,
        ];
        foreach ($page->extra as $k => $v) {
            if (!isset($shared[strtolower((string) $k)])) {
                $fields[ucfirst((string) $k)] = $v;
            }
        }

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, FrontMatter::serialize($fields, $page->body));
        rename($tmp, $file);
        unset($this->parsedCache[$file], $this->translationsOf[$page->filePath]);

        $page->translationFile = $file;
        $page->translated      = true;
        if (!in_array($page->lang, $page->translations, true)) {
            $page->translations[] = $page->lang;
        }
    }

    /** Remove one translation of a page. True when a file was deleted. */
    public function deleteTranslation(Page $page, string $lang): bool
    {
        if ($page->filePath === '' || $this->languages === null || !$this->languages->has($lang)
            || $this->languages->isDefault($lang)) {
            return false;
        }
        $file = Languages::translationFile($page->filePath, $lang);
        unset($this->parsedCache[$file], $this->translationsOf[$page->filePath]);
        return is_file($file) && @unlink($file);
    }

    /**
     * Rename a page's slug — renames its directory; children travel with it.
     * Mutates $page (slug, urlPath, filePath, childrenDir). No-op when the
     * sanitized new slug equals the current one. Throws on root page or
     * sibling collision.
     */
    public function rename(Page $page, string $newSlug): void
    {
        if ($page->slug === '' || $page->urlPath === '/') {
            throw new \RuntimeException('Cannot rename the root page.');
        }

        $newSlug = Slug::sanitize($newSlug);
        if ($newSlug === '' || $newSlug === $page->slug) {
            return;
        }

        $oldDir    = dirname($page->filePath);
        $parentDir = dirname($oldDir);
        $newDir    = $parentDir . '/' . $newSlug;

        if (is_dir($newDir)) {
            throw new \RuntimeException("A page with slug '{$newSlug}' already exists here.");
        }

        if (!@rename($oldDir, $newDir)) {
            throw new \RuntimeException("Failed to rename page directory.");
        }

        $page->slug        = $newSlug;
        $page->filePath    = $newDir . '/' . basename($page->filePath);
        $page->translationFile = $page->translationFile !== null ? $newDir . '/' . basename($page->translationFile) : null;
        $page->childrenDir = $newDir . '/';
        $page->urlPath     = rtrim(dirname($page->urlPath), '/') . '/' . $newSlug;

        $this->forgetDir($oldDir);
    }

    /**
     * Move a page under a new parent (by parent URL path). Children travel with it.
     * No-op when the new parent is the page's current parent. Throws on root page,
     * cycle (moving under self/descendant), or slug collision in the target.
     */
    public function move(Page $page, string $newParentUrl): void
    {
        if ($page->slug === '' || $page->urlPath === '/') {
            throw new \RuntimeException('Cannot move the root page.');
        }

        $newParentUrl = $this->normalizePath($newParentUrl);
        $currentParentUrl = rtrim(dirname($page->urlPath), '/');
        if ($currentParentUrl === '') {
            $currentParentUrl = '/';
        }
        if ($newParentUrl === $currentParentUrl) {
            return;
        }

        // Prevent moving a page under itself or any of its descendants.
        if ($newParentUrl === $page->urlPath
            || str_starts_with($newParentUrl . '/', $page->urlPath . '/')) {
            throw new \RuntimeException('Cannot move a page under itself or its descendants.');
        }

        $this->assertChildTemplateAllowed($newParentUrl, $page->template);

        $targetDir = $this->childrenDirForUrl($newParentUrl);
        @mkdir($targetDir, 0775, true);

        $oldDir = dirname($page->filePath);
        $newDir = rtrim($targetDir, '/') . '/' . $page->slug;

        if (is_dir($newDir)) {
            throw new \RuntimeException("A page with slug '{$page->slug}' already exists under '{$newParentUrl}'.");
        }

        if (!@rename($oldDir, $newDir)) {
            throw new \RuntimeException('Failed to move page directory.');
        }

        $page->filePath    = $newDir . '/' . basename($page->filePath);
        $page->translationFile = $page->translationFile !== null ? $newDir . '/' . basename($page->translationFile) : null;
        $page->childrenDir = $newDir . '/';
        $page->urlPath     = ($newParentUrl === '/' ? '' : $newParentUrl) . '/' . $page->slug;

        $this->forgetDir($oldDir);
    }

    /** Delete page by URL path. Returns true on success. */
    public function delete(string $urlPath): bool
    {
        $page = $this->find($urlPath);
        if ($page === null || !$page->filePath) {
            return false;
        }

        $deleted = @unlink($page->filePath);
        $this->forgetDir(dirname($page->filePath));

        if ($deleted) {
            $dir = dirname($page->filePath);
            if ($dir !== rtrim($this->pagesDir, '/') && is_dir($dir)) {
                // Remove sibling files (page-local assets), but never sub-directories
                // — those belong to child pages.
                foreach (glob($dir . '/*') ?: [] as $entry) {
                    if (is_file($entry)) {
                        @unlink($entry);
                    }
                }
                $remaining = array_filter(
                    glob($dir . '/{*,.*}', GLOB_BRACE) ?: [],
                    fn (string $f) => !in_array(basename($f), ['.', '..', '.DS_Store'], true),
                );
                if (empty($remaining)) {
                    @rmdir($dir);
                }
            }
        }

        return $deleted;
    }

    /** File modification time for a URL path (used for cache invalidation). */
    public function mtime(string $urlPath): int
    {
        $page = $this->find($urlPath);
        if (!$page || !$page->filePath || !is_file($page->filePath)) {
            return 0;
        }
        $mtime = (int) filemtime($page->filePath);
        if ($page->translationFile !== null && is_file($page->translationFile)) {
            $mtime = max($mtime, (int) filemtime($page->translationFile));
        }
        return $mtime;
    }

    /**
     * Return the directory where sub-pages of the given URL path should be placed.
     * E.g. for "/" returns content/pages/, for "/about" returns content/pages/about/.
     */
    public function childrenDirForUrl(string $urlPath): string
    {
        $urlPath = $this->normalizePath($urlPath);

        if ($urlPath === '/') {
            return rtrim($this->pagesDir, '/') . '/';
        }

        $parent = $this->find($urlPath);
        if ($parent === null) {
            return rtrim($this->pagesDir, '/') . '/';
        }

        return $parent->childrenDir;
    }

    /**
     * Returns the effective set of templates a child page may use under $parent.
     * Priority: explicit AllowedChildTemplates field → implicit rule (a "stream"
     * page restricts children to the same "stream" template) → unrestricted ([]).
     *
     * @return list<string>
     */
    public function effectiveAllowedChildTemplates(Page $parent): array
    {
        if (!empty($parent->allowedChildTemplates)) {
            return $parent->allowedChildTemplates;
        }
        if ($parent->template === 'stream') {
            return ['stream'];
        }
        return [];
    }

    /**
     * Templates that are dedicated child templates and should NOT appear in
     * the global template select.
     *
     * A template is "child-only" when:
     *   1. It is listed in at least one page's AllowedChildTemplates field.
     *   2. Every existing page that uses it as its own template is a
     *      sanctioned child — i.e. its parent explicitly allows that template
     *      via AllowedChildTemplates (or the implicit stream rule).
     *
     * This means a template already used under the correct parent (e.g. all
     * "gallery" pages under a "fotogalerie" parent) is still treated as
     * child-only, while a template used anywhere outside such a restriction
     * is kept in the global list.
     *
     * @return list<string>
     */
    public function childOnlyTemplates(): array
    {
        $pages = $this->all();

        // Build a URL → Page map and collect every template listed as a child template.
        $byUrl         = [];
        $listedAsChild = [];
        foreach ($pages as $page) {
            $byUrl[$page->urlPath] = $page;
            foreach ($page->allowedChildTemplates as $tpl) {
                $listedAsChild[$tpl] = true;
            }
        }

        if (empty($listedAsChild)) {
            return [];
        }

        // For each candidate template, check whether any page using it is
        // outside a sanctioned parent relationship.
        $usedOutsideRestriction = [];
        foreach ($pages as $page) {
            $tpl = $page->template;
            if (!isset($listedAsChild[$tpl])) {
                continue;
            }

            if ($page->urlPath === '/') {
                $usedOutsideRestriction[$tpl] = true;
                continue;
            }

            $parentUrl = rtrim(dirname($page->urlPath), '/') ?: '/';
            $parent    = $byUrl[$parentUrl] ?? null;

            if ($parent === null) {
                $usedOutsideRestriction[$tpl] = true;
                continue;
            }

            $allowed = $this->effectiveAllowedChildTemplates($parent);
            if (empty($allowed) || !in_array($tpl, $allowed, true)) {
                $usedOutsideRestriction[$tpl] = true;
            }
        }

        return array_values(array_filter(
            array_keys($listedAsChild),
            fn ($tpl) => !isset($usedOutsideRestriction[$tpl]),
        ));
    }

    /**
     * Whether a page under $parent (null = the root page) may use $template,
     * as far as child-only templates go: a child-only template is allowed only
     * where the parent's effective AllowedChildTemplates lists it, mirroring
     * the admin template select. Every other template passes here; a parent's
     * own restriction is enforced by assertChildTemplateAllowed().
     */
    public function templateAllowedUnder(?Page $parent, string $template): bool
    {
        if (!in_array($template, $this->childOnlyTemplates(), true)) {
            return true;
        }
        return $parent !== null
            && in_array($template, $this->effectiveAllowedChildTemplates($parent), true);
    }

    /**
     * Throws \RuntimeException if $childTemplate is not allowed under the
     * parent at $parentUrl. Empty/absent parent list = unrestricted.
     */
    public function assertChildTemplateAllowed(string $parentUrl, string $childTemplate): void
    {
        $parent = $this->find($parentUrl);
        if ($parent === null) {
            return;
        }

        $allowed = $this->effectiveAllowedChildTemplates($parent);
        if (empty($allowed)) {
            return;
        }

        if (!in_array($childTemplate, $allowed, true)) {
            throw new \RuntimeException(sprintf(
                "Template '%s' is not allowed under '%s'. Allowed: %s.",
                $childTemplate,
                $this->normalizePath($parentUrl),
                implode(', ', $allowed),
            ));
        }
    }

    // ─────────────────── Traversal ───────────────────

    /** Root page: the content file directly in $dir. */
    private function findRoot(string $dir, string $urlPath): ?Page
    {
        $file = $this->contentFileIn($dir);
        return $file !== null ? $this->buildPage($file, $urlPath) : null;
    }

    /**
     * Walks the path one segment at a time. Every ancestor is built too (its
     * file parse is memoized in $parsedCache), so the result carries its
     * parent chain for visibility inheritance.
     */
    private function findBySegments(string $dir, array $segments, string $currentUrl, ?Page $parent = null): ?Page
    {
        if (empty($segments)) {
            return null;
        }

        $segment = array_shift($segments);
        $file    = $this->findFileBySlug($dir, $segment);

        if ($file === null) {
            return null;
        }

        $urlPath = $currentUrl . '/' . $segment;
        $page    = $this->buildPage($file, $urlPath, $parent);

        if (empty($segments)) {
            return $page;
        }

        // Children live in the same directory as the content file (the slug directory)
        return $this->findBySegments(dirname($file), $segments, $urlPath, $page);
    }

    /**
     * Find the content .txt file for $slug inside $dir.
     * With the new convention, each page has its own sub-directory named after the slug,
     * and the content file inside is named after the template.
     */
    private function findFileBySlug(string $dir, string $slug): ?string
    {
        $pageDir = rtrim($dir, '/') . '/' . $slug;

        if (!is_dir($pageDir)) {
            return null;
        }

        return $this->contentFileIn($pageDir);
    }

    /**
     * The content file of the page directory $dir, or null when it has none.
     *
     * Normally the only `*.txt` there. When there are several, the first one
     * named after an existing template (`<name>.twig`) wins, then `page.txt`,
     * then the first alphabetically — never just whichever sorts first, so a
     * stray `a.txt` cannot replace the page. The clash is logged once per dir.
     */
    private function contentFileIn(string $dir): ?string
    {
        $dir   = rtrim($dir, '/');
        $files = array_values(array_filter(glob($dir . '/*.txt') ?: [], 'is_file'));
        if ($this->languages !== null && $this->languages->enabled()) {
            // `page.en.txt` is a translation, never a content file of its own.
            $files = array_values(array_filter($files, fn (string $f) => !$this->languages->isTranslationFile($f)));
        }
        if (count($files) <= 1) {
            return $files[0] ?? null;
        }

        $pick = null;
        if ($this->templatesDir !== null) {
            foreach ($files as $file) {
                if (is_file(rtrim($this->templatesDir, '/') . '/' . basename($file, '.txt') . '.twig')) {
                    $pick = $file;
                    break;
                }
            }
        }
        $pick ??= in_array($dir . '/page.txt', $files, true) ? $dir . '/page.txt' : $files[0];

        if ($this->logger !== null && !isset($this->warnedDirs[$dir])) {
            $this->warnedDirs[$dir] = true;
            $this->logger->warning('Several .txt files in a page directory; using ' . basename($pick), [
                'dir'   => $dir,
                'files' => array_map('basename', $files),
            ]);
        }

        return $pick;
    }

    // ─────────────────── Recursive scan ───────────────────

    /**
     * @return Page[]
     */
    private function scanDir(string $dir, string $urlBase, bool $includeDirectTxt = false, ?Page $parent = null): array
    {
        $dir   = rtrim($dir, '/');
        $pages = [];

        if ($includeDirectTxt) {
            // Root page: a .txt file living directly in pagesDir
            $file = $this->contentFileIn($dir);
            if ($file !== null) {
                $pages[] = $this->buildPage($file, '/');
            }
        }

        // Build sibling list first so we can sort by ($sort, title) before recursing.
        $siblings = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            $slug  = basename($subDir);
            $file  = $this->contentFileIn($subDir);

            if ($file === null) {
                continue;
            }

            $urlPath = ($urlBase === '' ? '' : rtrim($urlBase, '/')) . '/' . $slug;
            $page    = $this->buildPage($file, $urlPath, $parent);
            $siblings[] = ['subDir' => $subDir, 'urlPath' => $urlPath, 'page' => $page];
        }

        usort($siblings, function (array $a, array $b) {
            $sa = $a['page']->sort ?? PHP_INT_MAX;
            $sb = $b['page']->sort ?? PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp(strtolower($a['page']->title), strtolower($b['page']->title));
        });

        foreach ($siblings as $s) {
            $pages[] = $s['page'];
            $children = $this->scanDir($s['subDir'], $s['urlPath'], false, $s['page']);
            $pages    = array_merge($pages, $children);
        }

        return $pages;
    }

    // ─────────────────── Page builder ───────────────────

    private function buildPage(string $filePath, string $urlPath, ?Page $parent = null): Page
    {
        [$meta, $body] = $this->parseFrontMatterFromFile($filePath);

        $lang         = $this->language();
        $translated   = true;
        $translation  = null;
        $translations = $lang !== '' ? $this->translationsOf($filePath) : [];
        if ($this->translating()) {
            $translation = Languages::translationFile($filePath, $lang);
            $translated  = in_array($lang, $translations, true);
            if ($translated) {
                [$meta, $body] = $this->mergeTranslation($meta, $body, $translation, (string) ($meta['template'] ?? basename($filePath, '.txt')));
            }
        }

        $fileDir  = dirname($filePath);
        $isRoot   = (rtrim($fileDir, '/') === rtrim($this->pagesDir, '/'));
        $slug     = $isRoot ? '' : basename($fileDir);
        $template = $meta['template'] ?? basename($filePath, '.txt');

        $page = new Page(
            slug:      $slug,
            title:     (string) ($meta['title'] ?? $slug),
            body:      $body,
            metatitle: isset($meta['metatitle']) && $meta['metatitle'] !== '' ? $meta['metatitle'] : null,
            author:    $meta['author'] ?? null,
            updated:   $meta['updated'] ?? null,
            template:  $template,
            publishedAt: isset($meta['publishedat']) && $meta['publishedat'] !== '' ? $meta['publishedat'] : null,
            sort:      isset($meta['sort']) && $meta['sort'] !== '' ? (int) $meta['sort'] : null,
            allowedChildTemplates: $this->parseTemplateList($meta['allowedchildtemplates'] ?? ''),
            extra:     array_diff_key($meta, array_flip(self::RESERVED_KEYS)),
            group:     isset($meta['group']) && is_string($meta['group']) && trim($meta['group']) !== '' ? trim($meta['group']) : null,
            status:    Visibility::statusFromMeta($meta),
            publishAt: Visibility::datetimeFromMeta($meta, 'publishat'),
            expireAt:  Visibility::datetimeFromMeta($meta, 'expireat'),
            listing:   Visibility::listingFromMeta($meta),
            cascade:   filter_var($meta['cascade'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
        );
        $page->parent = $parent;

        $page->urlPath    = $urlPath ?: '/';
        $page->filePath   = $filePath;
        $page->lang         = $lang;
        $page->translated   = $translated;
        $page->translationFile = $translated ? $translation : null;
        $page->translations = $translations;
        $page->childrenDir = rtrim($fileDir, '/') . '/';

        return $page;
    }

    /**
     * Translation over the default file: its keys win except SHARED_KEYS and
     * `translate: false` fields; an empty translated body keeps the default one.
     *
     * @return array{array<string, mixed>, string}
     */
    private function mergeTranslation(array $meta, string $body, string $translationFile, string $template): array
    {
        [$tMeta, $tBody] = $this->parseFrontMatterFromFile($translationFile);
        $shared   = array_flip([...self::SHARED_KEYS, ...$this->sharedFieldsOf($template)]);

        // Status and its legacy mirror travel together.
        if (array_key_exists('status', $tMeta) || array_key_exists('published', $tMeta)) {
            unset($meta['status'], $meta['published']);
        }
        foreach ($tMeta as $key => $value) {
            if (!isset($shared[$key])) {
                $meta[$key] = $value;
            }
        }
        return [$meta, trim($tBody) !== '' ? $tBody : $body];
    }

    /** Drop cached parses / translation lists of everything under $dir (renamed, moved, deleted). */
    private function forgetDir(string $dir): void
    {
        foreach ([&$this->parsedCache, &$this->translationsOf] as &$cache) {
            foreach (array_keys($cache) as $key) {
                if (str_starts_with($key, $dir . '/')) {
                    unset($cache[$key]);
                }
            }
        }
    }

    /** @return list<string> the default language + every language with a translation file */
    private function translationsOf(string $filePath): array
    {
        if (!isset($this->translationsOf[$filePath])) {
            $found = [$this->languages->default()];
            foreach ($this->languages->codes() as $code) {
                if (!$this->languages->isDefault($code) && is_file(Languages::translationFile($filePath, $code))) {
                    $found[] = $code;
                }
            }
            $this->translationsOf[$filePath] = $found;
        }
        return $this->translationsOf[$filePath];
    }

    /** @return list<string> lower-cased `translate: false` field names of $template */
    private function sharedFieldsOf(string $template): array
    {
        return $this->sharedFields !== null ? ($this->sharedFields)($template) : [];
    }

    // ─────────────────── Front matter parsing ───────────────────

    /** Parse a .txt file. Returns [meta[], body]. Uses a small in-memory cache. */
    private function parseFrontMatterFromFile(string $filePath): array
    {
        if (isset($this->parsedCache[$filePath])) {
            return $this->parsedCache[$filePath];
        }
        $raw    = @file_get_contents($filePath);
        $result = $raw !== false ? FrontMatter::parse($raw) : [[], ''];
        $this->parsedCache[$filePath] = $result;
        return $result;
    }

    // ─────────────────── Serialization ───────────────────

    private function serialize(Page $page): string
    {
        $fields = [
            'Title'     => $page->title,
            // Slug is not stored – it is always the directory name
            'Metatitle' => $page->metatitle,
            // Template is not stored for 'page' – it is derivable from the filename
            'Template'  => ($page->template !== 'page') ? $page->template : null,
            'Status'      => $page->status,
            // Mirror of Status for pre-0.9 readers: a downgrade never exposes drafts.
            'Published'   => $page->isPublished() ? 'true' : 'false',
            'PublishAt'   => $page->publishAt,
            'ExpireAt'    => $page->expireAt,
            'PublishedAt' => $page->publishedAt,
            'Listing'     => $page->listing !== Visibility::LISTED ? $page->listing : null,
            'Cascade'     => $page->cascade ? null : 'false',
            'Author'      => $page->author,
            'Updated'     => $page->updated,
            'Sort'        => $page->sort !== null ? (string) $page->sort : null,
            'AllowedChildTemplates' => $page->allowedChildTemplates ? implode(', ', $page->allowedChildTemplates) : null,
            'Group'       => $page->group,
        ];

        foreach ($page->extra as $k => $v) {
            $fields[ucfirst($k)] = $v;
        }

        return FrontMatter::serialize($fields, $page->body);
    }

    // ─────────────────── Helpers ───────────────────

    /** @return list<string> */
    private function parseTemplateList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        return $path === '' ? '/' : $path;
    }
}

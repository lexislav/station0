<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Slug;

/**
 * Manages hierarchical flat-file content stored as .txt files.
 *
 * File format (Kirby-style front matter):
 *   Title: My Page
 *   Metatitle: SEO title (optional, falls back to Title)
 *   Template: article (optional – also derivable from filename)
 *   Published: true
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
 */
final class ContentRepository
{
    private array $parsedCache = [];

    public function __construct(private readonly string $pagesDir)
    {
        if (!is_dir($this->pagesDir)) {
            @mkdir($this->pagesDir, 0775, true);
        }
    }

    // ─────────────────── Public API ───────────────────

    /**
     * Find a page by its URL path (e.g. "/about/team").
     */
    public function find(string $urlPath): ?Page
    {
        $urlPath = $this->normalizePath($urlPath);

        if ($urlPath === '/') {
            return $this->findRoot($this->pagesDir, '/');
        }

        $segments = array_values(array_filter(explode('/', $urlPath)));
        return $this->findBySegments($this->pagesDir, $segments, '');
    }

    /**
     * Return ALL pages as flat list, sorted by urlPath.
     * Used by admin page list and dashboard count.
     */
    public function all(bool $includeUnpublished = true): array
    {
        $pages = $this->scanDir($this->pagesDir, '', true);

        if (!$includeUnpublished) {
            $pages = array_values(array_filter($pages, fn (Page $p) => $p->isLive()));
        }

        return $pages;
    }

    /**
     * Save a page. Pass $targetFilePath when creating a new file.
     * For updates, $page->filePath is used.
     */
    public function save(Page $page, ?string $targetFilePath = null): void
    {
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
        $page->childrenDir = $newDir . '/';
        $page->urlPath     = rtrim(dirname($page->urlPath), '/') . '/' . $newSlug;

        // Drop any cached parse for the old path.
        foreach (array_keys($this->parsedCache) as $key) {
            if (str_starts_with($key, $oldDir . '/')) {
                unset($this->parsedCache[$key]);
            }
        }
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
        $page->childrenDir = $newDir . '/';
        $page->urlPath     = ($newParentUrl === '/' ? '' : $newParentUrl) . '/' . $page->slug;

        foreach (array_keys($this->parsedCache) as $key) {
            if (str_starts_with($key, $oldDir . '/')) {
                unset($this->parsedCache[$key]);
            }
        }
    }

    /** Delete page by URL path. Returns true on success. */
    public function delete(string $urlPath): bool
    {
        $page = $this->find($urlPath);
        if ($page === null || !$page->filePath) {
            return false;
        }

        $deleted = @unlink($page->filePath);
        unset($this->parsedCache[$page->filePath]);

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
        return ($page && $page->filePath && is_file($page->filePath))
            ? (int) filemtime($page->filePath)
            : 0;
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
     * A template is "child-only" when it is listed in at least one page's
     * AllowedChildTemplates field AND it is never used as the own template of
     * any existing page. The second condition prevents accidentally hiding a
     * template that is still in use at the top level.
     *
     * @return list<string>
     */
    public function childOnlyTemplates(): array
    {
        $usedAsOwn   = [];
        $listedAsChild = [];

        foreach ($this->all() as $page) {
            $usedAsOwn[$page->template] = true;
            foreach ($page->allowedChildTemplates as $tpl) {
                $listedAsChild[$tpl] = true;
            }
        }

        return array_values(array_filter(
            array_keys($listedAsChild),
            fn ($tpl) => !isset($usedAsOwn[$tpl]),
        ));
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

    /** Root page: any .txt file directly in $dir (should be exactly one). */
    private function findRoot(string $dir, string $urlPath): ?Page
    {
        $files = glob(rtrim($dir, '/') . '/*.txt') ?: [];
        return !empty($files) ? $this->buildPage($files[0], $urlPath) : null;
    }

    private function findBySegments(string $dir, array $segments, string $currentUrl): ?Page
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

        if (empty($segments)) {
            return $this->buildPage($file, $urlPath);
        }

        // Children live in the same directory as the content file (the slug directory)
        return $this->findBySegments(dirname($file), $segments, $urlPath);
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

        $files = glob($pageDir . '/*.txt') ?: [];
        return !empty($files) ? $files[0] : null;
    }

    // ─────────────────── Recursive scan ───────────────────

    /**
     * @return Page[]
     */
    private function scanDir(string $dir, string $urlBase, bool $includeDirectTxt = false): array
    {
        $dir   = rtrim($dir, '/');
        $pages = [];

        if ($includeDirectTxt) {
            // Root page: a .txt file living directly in pagesDir
            foreach (glob($dir . '/*.txt') ?: [] as $file) {
                $pages[] = $this->buildPage($file, '/');
                break;
            }
        }

        // Build sibling list first so we can sort by ($sort, title) before recursing.
        $siblings = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            $slug  = basename($subDir);
            $files = glob($subDir . '/*.txt') ?: [];

            if (empty($files)) {
                continue;
            }

            $urlPath = ($urlBase === '' ? '' : rtrim($urlBase, '/')) . '/' . $slug;
            $page    = $this->buildPage($files[0], $urlPath);
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
            $children = $this->scanDir($s['subDir'], $s['urlPath'], false);
            $pages    = array_merge($pages, $children);
        }

        return $pages;
    }

    // ─────────────────── Page builder ───────────────────

    private function buildPage(string $filePath, string $urlPath): Page
    {
        [$meta, $body] = $this->parseFrontMatterFromFile($filePath);

        $fileDir  = dirname($filePath);
        $isRoot   = (rtrim($fileDir, '/') === rtrim($this->pagesDir, '/'));
        $slug     = $isRoot ? '' : basename($fileDir);
        $template = $meta['template'] ?? basename($filePath, '.txt');

        $page = new Page(
            slug:      $slug,
            title:     (string) ($meta['title'] ?? $slug),
            body:      $body,
            metatitle: isset($meta['metatitle']) && $meta['metatitle'] !== '' ? $meta['metatitle'] : null,
            published: filter_var($meta['published'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            author:    $meta['author'] ?? null,
            updated:   $meta['updated'] ?? null,
            template:  $template,
            publishedAt: isset($meta['publishedat']) && $meta['publishedat'] !== '' ? $meta['publishedat'] : null,
            sort:      isset($meta['sort']) && $meta['sort'] !== '' ? (int) $meta['sort'] : null,
            allowedChildTemplates: $this->parseTemplateList($meta['allowedchildtemplates'] ?? ''),
            extra:     array_diff_key($meta, array_flip(['title', 'metatitle', 'published', 'publishedat', 'author', 'updated', 'template', 'sort', 'allowedchildtemplates'])),
        );

        $page->urlPath    = $urlPath ?: '/';
        $page->filePath   = $filePath;
        $page->childrenDir = rtrim($fileDir, '/') . '/';

        return $page;
    }

    // ─────────────────── Front matter parsing ───────────────────

    /** Parse a .txt file. Returns [meta[], body]. Uses a small in-memory cache. */
    private function parseFrontMatterFromFile(string $filePath): array
    {
        if (isset($this->parsedCache[$filePath])) {
            return $this->parsedCache[$filePath];
        }
        $raw    = @file_get_contents($filePath);
        $result = $raw !== false ? $this->parseFrontMatter($raw) : [[], ''];
        $this->parsedCache[$filePath] = $result;
        return $result;
    }

    /**
     * Parse Kirby-style front matter.
     *
     *   Key: Value
     *   Key2: Value2
     *   ---
     *
     *   Body text here.
     *
     * Returns [meta_array, body_string].
     */
    private function parseFrontMatter(string $raw): array
    {
        $raw   = str_replace("\r\n", "\n", $raw);
        $lines = explode("\n", $raw);
        $meta  = [];
        $sepIdx = null;

        foreach ($lines as $i => $line) {
            if (rtrim($line) === '---') {
                $sepIdx = $i;
                break;
            }
        }

        if ($sepIdx !== null) {
            foreach (array_slice($lines, 0, $sepIdx) as $line) {
                if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*)$/', $line, $m)) {
                    $meta[strtolower($m[1])] = rtrim($m[2]);
                }
            }
            $body = ltrim(implode("\n", array_slice($lines, $sepIdx + 1)), "\n ");
        } else {
            $body = $raw;
        }

        return [$meta, $body];
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
            'Published'   => $page->published ? 'true' : 'false',
            'PublishedAt' => $page->publishedAt,
            'Author'      => $page->author,
            'Updated'     => $page->updated,
            'Sort'        => $page->sort !== null ? (string) $page->sort : null,
            'AllowedChildTemplates' => $page->allowedChildTemplates ? implode(', ', $page->allowedChildTemplates) : null,
        ];

        foreach ($page->extra as $k => $v) {
            $fields[ucfirst($k)] = $v;
        }

        $lines = [];
        foreach ($fields as $key => $val) {
            if ($val !== null && $val !== '') {
                $lines[] = "{$key}: {$val}";
            }
        }

        return implode("\n", $lines) . "\n---\n\n" . rtrim($page->body) . "\n";
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

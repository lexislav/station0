<?php

declare(strict_types=1);

namespace Station0\Service;

final class Page
{
    /** Full URL path, e.g. /about/team */
    public string $urlPath = '';

    /** Absolute path to the .txt file on disk */
    public string $filePath = '';

    /** Directory where this page's sub-pages live, e.g. /content/pages/about/ */
    public string $childrenDir = '';

    public function __construct(
        /** URL segment for this level (last part of urlPath) */
        public string $slug,
        public string $title,
        public string $body,
        public ?string $metatitle = null,
        public bool $published = true,
        public ?string $author = null,
        public ?string $updated = null,
        public string $template = 'page',
        /** ISO-ish datetime ("Y-m-d H:i" or "Y-m-d"). Future = scheduled. */
        public ?string $publishedAt = null,
        /** Explicit sibling sort order. Lower comes first; null falls back to title. */
        public ?int $sort = null,
        public array $extra = [],
    ) {}

    public function depth(): int
    {
        return $this->urlPath === '/' ? 0 : substr_count($this->urlPath, '/');
    }

    /** True when the page should be visible on the public site right now. */
    public function isLive(?int $now = null): bool
    {
        if (!$this->published) {
            return false;
        }
        if ($this->publishedAt === null || $this->publishedAt === '') {
            return true;
        }
        $ts = strtotime($this->publishedAt);
        return $ts === false || $ts <= ($now ?? time());
    }
}

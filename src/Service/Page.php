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
        public array $extra = [],
    ) {}

    public function depth(): int
    {
        return $this->urlPath === '/' ? 0 : substr_count($this->urlPath, '/');
    }
}

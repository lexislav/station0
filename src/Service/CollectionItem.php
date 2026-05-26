<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * A single item inside a Collection.
 *
 * Collections are headless content stores — they have no public URL and do not
 * appear in the page tree. Items are accessed from Twig via collection() and
 * collection_item() helper functions.
 *
 * Storage: site/content/collections/{collection}/{slug}/item.txt
 * Same Kirby-style front matter as Page files.
 */
final class CollectionItem
{
    /** Absolute path to the item.txt file */
    public string $filePath = '';

    public function __construct(
        /** Name of the parent collection directory, e.g. "banners" */
        public string $collection,
        /** URL-safe slug (directory name), e.g. "summer-sale" */
        public string $slug,
        public string $title,
        public string $body,
        public bool $published = true,
        /** Explicit sibling sort order — lower comes first; null falls back to title. */
        public ?int $sort = null,
        /** Any front-matter fields not recognised by CollectionRepository end up here. */
        public array $extra = [],
    ) {}

    /** True when the item should be visible. */
    public function isLive(): bool
    {
        return $this->published;
    }
}

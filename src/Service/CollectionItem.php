<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Visibility;

/**
 * A single item inside a Collection.
 *
 * Collections are headless content stores — they have no public URL and do not
 * appear in the page tree. Items are accessed from Twig via collection() and
 * collection_item() helper functions.
 *
 * Storage: site/content/collections/{collection}/{slug}/item.txt
 * Same Kirby-style front matter as Page files, incl. Status / PublishAt /
 * ExpireAt (see Visibility); no listing axis and no inheritance.
 *
 * @property bool $published Legacy alias of `$status === 'published'` (pre-0.9).
 */
final class CollectionItem
{
    /** Absolute path to the item.txt file */
    public string $filePath = '';

    /** Editorial status: draft | published | archived */
    public string $status;

    public function __construct(
        /** Name of the parent collection directory, e.g. "banners" */
        public string $collection,
        /** URL-safe slug (directory name), e.g. "summer-sale" */
        public string $slug,
        public string $title,
        public string $body,
        /** Legacy flag; used only when $status is null. */
        bool $published = true,
        /** Explicit sibling sort order — lower comes first; null falls back to title. */
        public ?int $sort = null,
        /** Any front-matter fields not recognised by CollectionRepository end up here. */
        public array $extra = [],
        ?string $status = null,
        public ?string $publishAt = null,
        public ?string $expireAt = null,
    ) {
        $this->status = $status !== null
            ? Visibility::normalizeStatus($status)
            : ($published ? Visibility::PUBLISHED : Visibility::DRAFT);
    }

    public function isPublished(): bool
    {
        return $this->status === Visibility::PUBLISHED;
    }

    /** draft | archived | scheduled | expired | live */
    public function state(?int $now = null): string
    {
        return Visibility::state($this->status, $this->publishAt, $this->expireAt, null, $now);
    }

    /** True when the item should be visible on the public site right now. */
    public function isLive(?int $now = null): bool
    {
        return $this->state($now) === Visibility::LIVE;
    }

    // ── Legacy `$item->published` (pre-0.9 property) ──

    public function __get(string $name): mixed
    {
        if ($name === 'published') {
            return $this->isPublished();
        }
        trigger_error('Undefined property: ' . self::class . '::$' . $name, E_USER_WARNING);
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name !== 'published') {
            throw new \LogicException('Cannot set undefined property ' . self::class . '::$' . $name);
        }
        $this->status = $value ? Visibility::PUBLISHED : Visibility::DRAFT;
    }

    public function __isset(string $name): bool
    {
        return $name === 'published';
    }
}

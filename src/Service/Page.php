<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Visibility;

/**
 * @property bool $published Legacy alias of `$status === 'published'` (pre-0.9).
 */
final class Page
{
    /** Full URL path, e.g. /about/team */
    public string $urlPath = '';

    /** Absolute path to the .txt file on disk */
    public string $filePath = '';

    /** Directory where this page's sub-pages live, e.g. /content/pages/about/ */
    public string $childrenDir = '';

    /**
     * Parent page, set by ContentRepository; drives visibility inheritance.
     * Null for the root page and for top-level pages — the root page is not
     * an ancestor here, so a draft homepage never hides the whole site.
     */
    public ?Page $parent = null;

    /** Editorial status: draft | published | archived (see Visibility) */
    public string $status;

    public function __construct(
        /** URL segment for this level (last part of urlPath) */
        public string $slug,
        public string $title,
        public string $body,
        public ?string $metatitle = null,
        /** Legacy flag; used only when $status is null. */
        bool $published = true,
        public ?string $author = null,
        public ?string $updated = null,
        public string $template = 'page',
        /** Display / sort date ("Y-m-d H:i" or "Y-m-d"). Schedules only while $publishAt is null (legacy). */
        public ?string $publishedAt = null,
        /** Explicit sibling sort order. Lower comes first; null falls back to title. */
        public ?int $sort = null,
        /** Child templates this page accepts. Empty = unrestricted. */
        public array $allowedChildTemplates = [],
        public array $extra = [],
        /** Admin menu group this page (and its subtree) belongs to; see NavGroups */
        public ?string $group = null,
        ?string $status = null,
        /** Published page is scheduled until then. */
        public ?string $publishAt = null,
        /** Published page is expired from then on. */
        public ?string $expireAt = null,
        /** listed | nav-hidden | unlisted */
        public string $listing = Visibility::LISTED,
    ) {
        $this->status = $status !== null
            ? Visibility::normalizeStatus($status)
            : ($published ? Visibility::PUBLISHED : Visibility::DRAFT);
    }

    public function depth(): int
    {
        return $this->urlPath === '/' ? 0 : substr_count($this->urlPath, '/');
    }

    public function isPublished(): bool
    {
        return $this->status === Visibility::PUBLISHED;
    }

    /** This page's own state, ignoring ancestors: draft | archived | scheduled | expired | live. */
    public function ownState(?int $now = null): string
    {
        return Visibility::state($this->status, $this->publishAt, $this->expireAt, $this->publishedAt, $now);
    }

    /**
     * Effective state: the own state, or `hidden` when the page itself is live
     * but an ancestor is not.
     */
    public function state(?int $now = null): string
    {
        $own = $this->ownState($now);
        if ($own !== Visibility::LIVE) {
            return $own;
        }
        return $this->blockingAncestor($now) !== null ? Visibility::HIDDEN : Visibility::LIVE;
    }

    /** True when the page and all its ancestors are visible on the public site right now. */
    public function isLive(?int $now = null): bool
    {
        return $this->state($now) === Visibility::LIVE;
    }

    /** The outermost non-live ancestor (the root cause), or null. */
    public function blockingAncestor(?int $now = null): ?Page
    {
        $blocker = null;
        for ($p = $this->parent; $p !== null; $p = $p->parent) {
            if ($p->ownState($now) !== Visibility::LIVE) {
                $blocker = $p;
            }
        }
        return $blocker;
    }

    /** Public status code for a non-live page: 410 when it (or the hiding ancestor) is archived / expired, else 404. */
    public function httpStatus(?int $now = null): int
    {
        $own = $this->ownState($now);
        if ($own !== Visibility::LIVE) {
            return Visibility::httpStatus($own);
        }
        $blocker = $this->blockingAncestor($now);
        return $blocker !== null ? Visibility::httpStatus($blocker->ownState($now)) : 200;
    }

    /** Shown by navigation helpers (`nav_pages()`, `top_level_pages()`). */
    public function inNav(): bool
    {
        return $this->listing === Visibility::LISTED;
    }

    /** Shown in listings (`child_pages()`); unlisted pages are reachable by URL only. */
    public function isListed(): bool
    {
        return $this->listing !== Visibility::UNLISTED;
    }

    // ── Legacy `$page->published` (pre-0.9 property) ──

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

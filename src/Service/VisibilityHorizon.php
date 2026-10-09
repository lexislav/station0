<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Visibility;

/**
 * Keeps the rendered-HTML cache honest about time.
 *
 * Whether a page is live is decided per request, but rendered bodies are
 * cached — and a block template may call child_pages() / collection(), so
 * another page's PublishAt / ExpireAt can be baked into cached HTML. Saves
 * flush the cache; time passing does not. This stores the next scheduled
 * transition (min future PublishAt / ExpireAt / legacy PublishedAt of all
 * pages and collection items) as a cache entry and flushes the cache once
 * it is reached.
 *
 * Cost: one cache read per request; a full content scan only after a flush
 * (every admin save flushes) or a transition.
 */
final class VisibilityHorizon
{
    public const KEY = 'visibility:horizon';

    private const NONE = 'none';

    private bool $checked = false;

    public function __construct(
        private readonly FileCache $cache,
        private readonly ContentRepository $pages,
        private readonly ?CollectionRepository $collections = null,
    ) {}

    /**
     * Flushes the cache when a scheduled transition has passed since it was
     * filled (or when the horizon is unknown). Runs once per instance (= per
     * request) unless $now is given. Returns true when it flushed.
     */
    public function check(?int $now = null): bool
    {
        if ($this->checked && $now === null) {
            return false;
        }
        $this->checked = true;
        $now ??= time();

        $stored = $this->cache->get(self::KEY);
        if ($stored === self::NONE || ($stored !== null && (int) $stored > $now)) {
            return false;
        }

        // Transition passed — or no horizon yet (fresh cache, first run after
        // an upgrade): nothing cached can be trusted to be time-correct.
        $this->cache->flush();
        $next = $this->next($now);
        $this->cache->set(self::KEY, $next === null ? self::NONE : (string) $next);
        return true;
    }

    /** Next moment any page or collection item changes state on its own; null = none pending. */
    public function next(int $now): ?int
    {
        $next = null;
        foreach ($this->pages->all() as $page) {
            $next = self::min($next, Visibility::nextTransition(
                $page->status, $page->publishAt, $page->expireAt, $page->publishedAt, $now,
            ));
        }
        if ($this->collections !== null) {
            foreach ($this->collections->names() as $name) {
                foreach ($this->collections->items($name, true) as $item) {
                    $next = self::min($next, Visibility::nextTransition(
                        $item->status, $item->publishAt, $item->expireAt, null, $now,
                    ));
                }
            }
        }
        return $next;
    }

    private static function min(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}

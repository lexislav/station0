<?php

declare(strict_types=1);

namespace Station0\Support;

/**
 * Publication and listing rules shared by pages and collection items.
 *
 * Two independent axes:
 *
 *   Publication — is the URL reachable?
 *     Status:    draft | published | archived   (legacy `Published: true|false`)
 *     PublishAt: a published entry is "scheduled" until then
 *     ExpireAt:  a published entry is "expired" from then on
 *
 *   Listing (pages only) — where does a live page show up?
 *     Listing:   listed | nav-hidden | unlisted
 *
 * Datetimes are stored as "Y-m-d H:i" in the site timezone (`timezone`
 * config); anything strtotime() understands is accepted on read.
 *
 * Legacy scheduling: while `PublishAt` is absent, a future `PublishedAt`
 * still schedules the entry, exactly as before 0.9.
 */
final class Visibility
{
    public const DRAFT     = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED  = 'archived';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    /** Effective states (computed). DRAFT / ARCHIVED double as states. */
    public const LIVE      = 'live';
    public const SCHEDULED = 'scheduled';
    public const EXPIRED   = 'expired';
    /** Own state is live, but an ancestor page is not. */
    public const HIDDEN    = 'hidden';

    public const LISTED     = 'listed';
    public const NAV_HIDDEN = 'nav-hidden';
    public const UNLISTED   = 'unlisted';

    public const LISTINGS = [self::LISTED, self::NAV_HIDDEN, self::UNLISTED];

    public const FORMAT = 'Y-m-d H:i';

    /** `Status:` when valid, else the legacy `Published:` flag (default true). */
    public static function statusFromMeta(array $meta): string
    {
        $status = strtolower(trim((string) (is_scalar($meta['status'] ?? null) ? $meta['status'] : '')));
        if (in_array($status, self::STATUSES, true)) {
            return $status;
        }
        return filter_var($meta['published'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? self::PUBLISHED : self::DRAFT;
    }

    public static function listingFromMeta(array $meta): string
    {
        return self::normalizeListing((string) (is_scalar($meta['listing'] ?? null) ? $meta['listing'] : ''));
    }

    /** A front-matter datetime value: trimmed string, null when empty / not a string. */
    public static function datetimeFromMeta(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    public static function normalizeStatus(string $raw, string $fallback = self::PUBLISHED): string
    {
        $raw = strtolower(trim($raw));
        return in_array($raw, self::STATUSES, true) ? $raw : $fallback;
    }

    public static function normalizeListing(string $raw): string
    {
        $raw = strtolower(trim($raw));
        return in_array($raw, self::LISTINGS, true) ? $raw : self::LISTED;
    }

    /**
     * Admin input (HTML datetime-local "2026-05-12T14:30", or any strtotime
     * format) → stored "Y-m-d H:i". Empty or unparsable → null.
     */
    public static function normalizeInput(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : date(self::FORMAT, $ts);
    }

    /** Unix timestamp of a stored datetime; null when empty or unparsable. */
    public static function timestamp(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    /**
     * Effective own state: draft | archived | scheduled | expired | live.
     *
     * PublishAt / ExpireAt only matter for `published` — a draft stays a
     * draft whatever its schedule says.
     */
    public static function state(
        string $status,
        ?string $publishAt,
        ?string $expireAt,
        ?string $legacyPublishedAt = null,
        ?int $now = null,
    ): string {
        if ($status === self::DRAFT || $status === self::ARCHIVED) {
            return $status;
        }
        $now ??= time();

        $publishTs = self::publishTimestamp($publishAt, $legacyPublishedAt);
        if ($publishTs !== null && $publishTs > $now) {
            return self::SCHEDULED;
        }
        $expireTs = self::timestamp($expireAt);
        if ($expireTs !== null && $expireTs <= $now) {
            return self::EXPIRED;
        }
        return self::LIVE;
    }

    /**
     * Next moment the state of a published entry changes on its own
     * (becomes live or expires); null when nothing is pending.
     */
    public static function nextTransition(
        string $status,
        ?string $publishAt,
        ?string $expireAt,
        ?string $legacyPublishedAt = null,
        ?int $now = null,
    ): ?int {
        if ($status !== self::PUBLISHED) {
            return null;
        }
        $now ??= time();
        $next = null;
        foreach ([self::publishTimestamp($publishAt, $legacyPublishedAt), self::timestamp($expireAt)] as $ts) {
            if ($ts !== null && $ts > $now && ($next === null || $ts < $next)) {
                $next = $ts;
            }
        }
        return $next;
    }

    /** Public HTTP status for a non-live state: gone for good (410) or not found (404). */
    public static function httpStatus(string $state): int
    {
        return in_array($state, [self::ARCHIVED, self::EXPIRED], true) ? 410 : 404;
    }

    /**
     * Validation of an admin-entered schedule; returns a translation key
     * (`visibility_error_expire_before_publish`) or null when valid.
     */
    public static function validate(?string $publishAt, ?string $expireAt): ?string
    {
        $publishTs = self::timestamp($publishAt);
        $expireTs  = self::timestamp($expireAt);
        if ($publishTs !== null && $expireTs !== null && $expireTs <= $publishTs) {
            return 'visibility_error_expire_before_publish';
        }
        return null;
    }

    /** PublishAt when set, else (legacy) PublishedAt. */
    private static function publishTimestamp(?string $publishAt, ?string $legacyPublishedAt): ?int
    {
        return self::timestamp($publishAt) ?? self::timestamp($legacyPublishedAt);
    }
}

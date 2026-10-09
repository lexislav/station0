<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Station0\Support\Visibility as V;

final class VisibilityTest extends TestCase
{
    private const NOW    = '2026-10-09 12:00';
    private const PAST   = '2026-10-01 08:00';
    private const FUTURE = '2026-11-01 08:00';

    private static function now(): int
    {
        return (int) strtotime(self::NOW);
    }

    /** @return iterable<string, array{string, ?string, ?string, ?string, string}> */
    public static function stateMatrix(): iterable
    {
        // status, PublishAt, ExpireAt, legacy PublishedAt → state
        yield 'published, no dates'          => [V::PUBLISHED, null, null, null, V::LIVE];
        yield 'published, past publish'      => [V::PUBLISHED, self::PAST, null, null, V::LIVE];
        yield 'published, future publish'    => [V::PUBLISHED, self::FUTURE, null, null, V::SCHEDULED];
        yield 'published, future expire'     => [V::PUBLISHED, null, self::FUTURE, null, V::LIVE];
        yield 'published, past expire'       => [V::PUBLISHED, null, self::PAST, null, V::EXPIRED];
        yield 'published, window open'       => [V::PUBLISHED, self::PAST, self::FUTURE, null, V::LIVE];
        yield 'published, window closed'     => [V::PUBLISHED, self::PAST, self::PAST, null, V::EXPIRED];
        yield 'published, window ahead'      => [V::PUBLISHED, self::FUTURE, self::FUTURE, null, V::SCHEDULED];
        yield 'expire exactly now'           => [V::PUBLISHED, null, self::NOW, null, V::EXPIRED];
        yield 'publish exactly now'          => [V::PUBLISHED, self::NOW, null, null, V::LIVE];
        yield 'draft ignores schedule'       => [V::DRAFT, self::PAST, self::FUTURE, null, V::DRAFT];
        yield 'draft with future publish'    => [V::DRAFT, self::FUTURE, null, null, V::DRAFT];
        yield 'archived wins'                => [V::ARCHIVED, self::PAST, self::FUTURE, null, V::ARCHIVED];
        yield 'legacy future PublishedAt'    => [V::PUBLISHED, null, null, self::FUTURE, V::SCHEDULED];
        yield 'legacy past PublishedAt'      => [V::PUBLISHED, null, null, self::PAST, V::LIVE];
        yield 'PublishAt overrides legacy'   => [V::PUBLISHED, self::PAST, null, self::FUTURE, V::LIVE];
        yield 'invalid PublishAt → legacy'   => [V::PUBLISHED, 'not a date', null, self::FUTURE, V::SCHEDULED];
        yield 'invalid dates are ignored'    => [V::PUBLISHED, 'nope', 'nope', null, V::LIVE];
    }

    #[DataProvider('stateMatrix')]
    public function testState(string $status, ?string $publishAt, ?string $expireAt, ?string $legacy, string $expected): void
    {
        self::assertSame($expected, V::state($status, $publishAt, $expireAt, $legacy, self::now()));
    }

    public function testStatusFromMetaPrefersStatusOverLegacyFlag(): void
    {
        self::assertSame(V::PUBLISHED, V::statusFromMeta([]));
        self::assertSame(V::PUBLISHED, V::statusFromMeta(['published' => 'true']));
        self::assertSame(V::DRAFT, V::statusFromMeta(['published' => 'false']));
        self::assertSame(V::ARCHIVED, V::statusFromMeta(['status' => 'Archived', 'published' => 'false']));
        self::assertSame(V::DRAFT, V::statusFromMeta(['status' => 'draft', 'published' => 'true']));
        // Unknown status falls back to the legacy flag.
        self::assertSame(V::DRAFT, V::statusFromMeta(['status' => 'bogus', 'published' => 'false']));
    }

    public function testListing(): void
    {
        self::assertSame(V::LISTED, V::listingFromMeta([]));
        self::assertSame(V::NAV_HIDDEN, V::listingFromMeta(['listing' => 'nav-hidden']));
        self::assertSame(V::UNLISTED, V::listingFromMeta(['listing' => ' Unlisted ']));
        self::assertSame(V::LISTED, V::listingFromMeta(['listing' => 'whatever']));
    }

    public function testNormalizeInput(): void
    {
        self::assertSame('2026-05-12 14:30', V::normalizeInput('2026-05-12T14:30'));
        self::assertSame('2026-05-12 00:00', V::normalizeInput('2026-05-12'));
        self::assertNull(V::normalizeInput(''));
        self::assertNull(V::normalizeInput('garbage'));
    }

    public function testNextTransition(): void
    {
        $now = self::now();
        self::assertNull(V::nextTransition(V::PUBLISHED, null, null, null, $now));
        self::assertNull(V::nextTransition(V::DRAFT, self::FUTURE, null, null, $now));
        self::assertSame(strtotime(self::FUTURE), V::nextTransition(V::PUBLISHED, self::FUTURE, null, null, $now));
        self::assertSame(strtotime(self::FUTURE), V::nextTransition(V::PUBLISHED, self::PAST, self::FUTURE, null, $now));
        self::assertSame(strtotime(self::FUTURE), V::nextTransition(V::PUBLISHED, null, null, self::FUTURE, $now));
        self::assertSame(
            strtotime('2026-10-20 08:00'),
            V::nextTransition(V::PUBLISHED, '2026-10-20 08:00', self::FUTURE, null, $now),
        );
    }

    public function testHttpStatusAndValidation(): void
    {
        self::assertSame(410, V::httpStatus(V::EXPIRED));
        self::assertSame(410, V::httpStatus(V::ARCHIVED));
        self::assertSame(404, V::httpStatus(V::DRAFT));
        self::assertSame(404, V::httpStatus(V::SCHEDULED));

        self::assertNull(V::validate(self::PAST, self::FUTURE));
        self::assertNull(V::validate(null, self::PAST));
        self::assertSame('visibility_error_expire_before_publish', V::validate(self::FUTURE, self::PAST));
        self::assertSame('visibility_error_expire_before_publish', V::validate(self::PAST, self::PAST));
    }
}

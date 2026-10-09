<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Station0\Service\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_000_000;

    private function make(): RateLimiter
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return new RateLimiter($pdo, fn () => $this->now);
    }

    public function testLocksAfterMaxHitsUntilLockExpires(): void
    {
        $limiter = $this->make();

        self::assertSame(2, $limiter->hit('k', 3, 900)['remaining']);
        self::assertTrue($limiter->hit('k', 3, 900)['allowed']);
        $third = $limiter->hit('k', 3, 900);
        self::assertTrue($third['allowed']);
        self::assertSame(900, $third['retryAfter']);
        self::assertSame(900, $limiter->blocked('k'));

        $this->now += 100;
        $blocked = $limiter->hit('k', 3, 900);
        self::assertFalse($blocked['allowed']);
        self::assertSame(800, $blocked['retryAfter']);

        $this->now += 800;
        self::assertSame(0, $limiter->blocked('k'));
        $fresh = $limiter->hit('k', 3, 900);
        self::assertTrue($fresh['allowed']);
        self::assertSame(2, $fresh['remaining']);
    }

    public function testWindowResetsCountAndKeysAreIndependent(): void
    {
        $limiter = $this->make();
        $limiter->hit('a', 3, 60);
        $limiter->hit('a', 3, 60);
        $limiter->hit('b', 3, 60);

        self::assertSame(1, $limiter->hit('b', 3, 60)['remaining'], 'b counts on its own');

        $this->now += 61;
        self::assertSame(2, $limiter->hit('a', 3, 60)['remaining'], 'a new window starts over');
    }

    public function testCustomLockAndReset(): void
    {
        $limiter = $this->make();
        $limiter->hit('k', 1, 60, 3600);
        self::assertSame(3600, $limiter->blocked('k'));
        $limiter->reset('k');
        self::assertSame(0, $limiter->blocked('k'));
    }
}

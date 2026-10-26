<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PDO;
use PHPUnit\Framework\TestCase;
use Station0\Plugin\Migrations;
use Station0\Plugin\Redirects\Log;

final class LogTest extends TestCase
{
    private function log(int $cap = 5000): Log
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Migrations::apply($pdo, Log::migrations());
        return new Log($pdo, ['/wp-*', '*.php'], $cap);
    }

    public function testRecordsCountsAndStripsQueries(): void
    {
        $log = $this->log();
        self::assertTrue($log->record404('/missing/', 'https://example.com/page?token=secret'));
        $log->record404('/missing');
        $log->record404('/other');

        $entries = $log->entries();
        self::assertSame('/missing', $entries[0]['path']);
        self::assertSame(2, $entries[0]['hits']);
        self::assertSame('https://example.com/page', $entries[0]['referrer']);
        self::assertCount(2, $entries);
    }

    public function testIgnoresGlobsAndIgnoredPaths(): void
    {
        $log = $this->log();
        self::assertFalse($log->record404('/wp-login'));
        self::assertFalse($log->record404('/x/setup.php'));
        $log->record404('/noise');
        $log->ignore('/noise');
        self::assertSame([], $log->entries());
        self::assertSame('/noise', $log->entries(10, true)[0]['path']);
        $log->ignore('/noise', false);
        self::assertCount(1, $log->entries());
    }

    public function testTruncatesAndCaps(): void
    {
        $log = $this->log(100);
        $log->record404('/' . str_repeat('a', 2000));
        self::assertSame(Log::MAX_PATH, strlen($log->entries()[0]['path']));

        for ($i = 0; $i < 150; $i++) {
            $log->record404('/p' . $i);
        }
        $log->prune();
        self::assertCount(100, $log->entries(500));
        self::assertSame(0, $log->clear() - 100);
        self::assertSame([], $log->entries());
    }

    public function testHitCounters(): void
    {
        $log = $this->log();
        $log->hit('abc');
        $log->hit('abc');
        self::assertSame(2, $log->hits()['abc']['hits']);
    }
}

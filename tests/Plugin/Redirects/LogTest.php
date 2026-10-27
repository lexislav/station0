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

    public function testSearchFiltersSortsAndPages(): void
    {
        $log = $this->log();
        foreach (['/a-one' => 3, '/b_two' => 1, '/c-three' => 2] as $path => $hits) {
            for ($i = 0; $i < $hits; $i++) {
                $log->record404($path, $path === '/c-three' ? 'https://news.example/x' : null);
            }
        }
        $paths = fn (array $r) => array_column($r['entries'], 'path');

        self::assertSame(['/a-one', '/c-three', '/b_two'], $paths($log->search()));
        self::assertSame(['/a-one', '/b_two', '/c-three'], $paths($log->search(['sort' => 'path', 'dir' => 'asc'])));
        self::assertSame(['/b_two', '/c-three', '/a-one'], $paths($log->search(['sort' => 'hits', 'dir' => 'asc'])));
        self::assertSame(['/a-one', '/c-three'], $paths($log->search(['q' => '-'])));
        self::assertSame(['/b_two'], $paths($log->search(['q' => '_'])), '_ is literal, not a LIKE wildcard');
        self::assertSame(['/c-three'], $paths($log->search(['ref' => 'news'])));
        self::assertSame(['/a-one', '/b_two'], $paths($log->search(['ref' => '-', 'sort' => 'path', 'dir' => 'asc'])));
        self::assertSame(['/a-one', '/c-three'], $paths($log->search(['min' => 2])));
        self::assertCount(3, $log->search(['days' => 1])['entries']);
        self::assertSame(['/b_two'], $paths($log->search(['sort' => 'nonsense; DROP', 'dir' => 'asc'], 1)), 'unknown sort falls back to hits');

        $page = $log->search(['sort' => 'path', 'dir' => 'asc'], 2, 2);
        self::assertSame(['/c-three'], $paths($page));
        self::assertSame(3, $page['total']);
    }

    public function testBulkIgnoreAndForgetResolved(): void
    {
        $log = $this->log();
        foreach (['/a', '/b', '/c', '/d'] as $path) {
            $log->record404($path);
        }
        $log->ignore(['/a', '/b']);
        self::assertSame(['/c', '/d'], array_column($log->search(['sort' => 'path', 'dir' => 'asc'])['entries'], 'path'));
        self::assertSame(2, $log->search(['ignored' => true])['total']);

        self::assertSame(2, $log->forgetResolved(fn (string $path) => in_array($path, ['/a', '/c'], true)));
        self::assertSame(['/d'], array_column($log->entries(), 'path'));
        self::assertSame(['/b'], array_column($log->entries(10, true), 'path'));
    }
}

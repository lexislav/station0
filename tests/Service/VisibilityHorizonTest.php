<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\VisibilityHorizon;

final class VisibilityHorizonTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s0-horizon-' . bin2hex(random_bytes(6));
        @mkdir($this->root . '/pages', 0775, true);
        @mkdir($this->root . '/collections', 0775, true);
        file_put_contents($this->root . '/pages/page.txt', "Title: Home\n---\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $relPath, string $frontMatter): void
    {
        $file = $this->root . '/' . $relPath;
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, "Title: x\n{$frontMatter}---\n");
    }

    private function make(FileCache $cache): VisibilityHorizon
    {
        return new VisibilityHorizon(
            $cache,
            new ContentRepository($this->root . '/pages'),
            new CollectionRepository($this->root . '/collections'),
        );
    }

    private static function ts(string $s): int
    {
        return (int) strtotime($s);
    }

    public function testNextTransitionAcrossPagesAndItems(): void
    {
        $this->write('pages/a/page.txt', "PublishAt: 2026-10-20 10:00\n");
        $this->write('pages/b/page.txt', "ExpireAt: 2026-10-15 10:00\n");
        $this->write('pages/c/page.txt', "Status: draft\nPublishAt: 2026-10-10 10:00\n");    // draft: no transition
        $this->write('pages/d/page.txt', "PublishedAt: 2026-10-12 10:00\n");                  // legacy schedule
        $this->write('collections/banners/x/item.txt', "ExpireAt: 2026-10-11 10:00\n");

        $now = self::ts('2026-10-09 12:00');
        self::assertSame(self::ts('2026-10-11 10:00'), $this->make(new FileCache($this->root . '/cache'))->next($now));
    }

    public function testFlushesOnceTheHorizonPasses(): void
    {
        $this->write('pages/a/page.txt', "PublishAt: 2026-10-10 10:00\n");
        $cache = new FileCache($this->root . '/cache');

        // Unknown horizon → flush (stale pre-upgrade entries), then remember it.
        $cache->set('page:old', 'stale');
        self::assertTrue($this->make($cache)->check(self::ts('2026-10-09 12:00')));
        self::assertNull($cache->get('page:old'));
        self::assertSame((string) self::ts('2026-10-10 10:00'), $cache->get(VisibilityHorizon::KEY));

        // Before the horizon: cached HTML survives.
        $cache->set('page:listing', '<ul>without a</ul>');
        self::assertFalse($this->make($cache)->check(self::ts('2026-10-10 09:59')));
        self::assertSame('<ul>without a</ul>', $cache->get('page:listing'));

        // Horizon reached: flushed, nothing further pending.
        self::assertTrue($this->make($cache)->check(self::ts('2026-10-10 10:00')));
        self::assertNull($cache->get('page:listing'));
        self::assertSame('none', $cache->get(VisibilityHorizon::KEY));
        self::assertFalse($this->make($cache)->check(self::ts('2030-01-01 00:00')));
    }

    public function testChecksOncePerInstance(): void
    {
        $cache   = new FileCache($this->root . '/cache');
        $horizon = $this->make($cache);
        self::assertTrue($horizon->check());
        $cache->flush();                       // horizon entry gone…
        self::assertFalse($horizon->check());  // …but this request already checked
    }
}

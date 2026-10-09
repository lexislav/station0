<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionItem;
use Station0\Service\CollectionRepository;
use Station0\Support\FrontMatter;
use Station0\Support\Visibility as V;

final class CollectionVisibilityTest extends TestCase
{
    private const PAST   = '2000-01-01 00:00';
    private const FUTURE = '2099-01-01 00:00';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-collvis-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function addItem(string $collection, string $slug, string $frontMatter = ''): void
    {
        @mkdir("{$this->dir}/{$collection}/{$slug}", 0775, true);
        file_put_contents("{$this->dir}/{$collection}/{$slug}/item.txt", "Title: {$slug}\n{$frontMatter}---\nBody\n");
    }

    /** @param list<CollectionItem> $items @return list<string> */
    private static function slugs(array $items): array
    {
        return array_map(fn (CollectionItem $i) => $i->slug, $items);
    }

    public function testStatesAndLegacyFlag(): void
    {
        $this->addItem('banners', 'a');
        $this->addItem('banners', 'b', "Published: false\n");
        $this->addItem('banners', 'c', "PublishAt: " . self::FUTURE . "\n");
        $this->addItem('banners', 'd', "ExpireAt: " . self::PAST . "\n");
        $this->addItem('banners', 'e', "Status: archived\n");
        $this->addItem('banners', 'f', "PublishAt: " . self::PAST . "\nExpireAt: " . self::FUTURE . "\n");

        $repo = new CollectionRepository($this->dir);
        self::assertSame(['a', 'f'], self::slugs($repo->items('banners')));
        self::assertSame(V::SCHEDULED, $repo->find('banners', 'c')->state());
        self::assertSame(V::EXPIRED, $repo->find('banners', 'd')->state());
        self::assertSame(V::ARCHIVED, $repo->find('banners', 'e')->state());
        self::assertFalse($repo->find('banners', 'b')->published);
        self::assertSame([], $repo->find('banners', 'f')->extra);
    }

    public function testSaveRoundTrip(): void
    {
        $this->addItem('banners', 'a');
        $repo = new CollectionRepository($this->dir);
        $item = $repo->find('banners', 'a');
        $item->status    = V::PUBLISHED;
        $item->publishAt = '2026-01-01 08:00';
        $item->expireAt  = '2026-02-01 08:00';
        $repo->save($item);

        [$meta] = FrontMatter::parse((string) file_get_contents($item->filePath));
        self::assertSame('published', $meta['status']);
        self::assertSame('true', $meta['published']);
        self::assertSame('2026-01-01 08:00', $meta['publishat']);
        self::assertSame('2026-02-01 08:00', $meta['expireat']);
    }

    public function testSchemaFieldNamedStatusStaysASchemaField(): void
    {
        @mkdir($this->dir . '/projects', 0775, true);
        file_put_contents($this->dir . '/projects/_collection.yaml', "fields:\n  status:\n    type: text\n");
        $this->addItem('projects', 'p', "Published: false\nStatus: in progress\n");

        $repo = new CollectionRepository($this->dir);
        $item = $repo->find('projects', 'p');
        self::assertSame(['status' => 'in progress'], $item->extra);
        self::assertSame(V::DRAFT, $item->status, 'visibility falls back to the Published flag');

        $item->status = V::PUBLISHED;
        $repo->save($item);
        $raw = (string) file_get_contents($item->filePath);
        self::assertSame(1, substr_count($raw, 'Status:'));
        [$meta] = FrontMatter::parse($raw);
        self::assertSame('in progress', $meta['status']);
        self::assertSame('true', $meta['published']);
    }
}

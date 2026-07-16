<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\MediaService;

/**
 * Guards the source-file disclosure fix: resolveAsset() must serve real assets
 * but never a page's/collection item's content `.txt` source (which leaks raw
 * front matter and the body of unpublished content).
 */
final class MediaServiceResolveAssetTest extends TestCase
{
    private string $root;
    private string $pagesDir;
    private string $collectionsDir;

    protected function setUp(): void
    {
        $this->root           = sys_get_temp_dir() . '/station0-media-' . bin2hex(random_bytes(4));
        $this->pagesDir       = $this->root . '/pages';
        $this->collectionsDir = $this->root . '/collections';

        // A draft page with an asset alongside its content source file.
        mkdir($this->pagesDir . '/about', 0775, true);
        file_put_contents(
            $this->pagesDir . '/about/page.txt',
            "Title: About\nPublished: false\nAuthor: admin\n---\n\nSecret draft body.\n",
        );
        file_put_contents($this->pagesDir . '/about/photo.png', 'PNGDATA');

        // A collection item with an asset alongside its item.txt source file.
        mkdir($this->collectionsDir . '/banners/sale', 0775, true);
        file_put_contents(
            $this->collectionsDir . '/banners/sale/item.txt',
            "Title: Sale\nPublished: false\n---\n\nSecret item body.\n",
        );
        file_put_contents($this->collectionsDir . '/banners/sale/bg.png', 'PNGDATA');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function make(): MediaService
    {
        return new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir, $this->collectionsDir);
    }

    public function testDoesNotServePageContentSourceFile(): void
    {
        self::assertNull($this->make()->resolveAsset('about/page.txt'));
    }

    public function testDoesNotServeCollectionItemSourceFile(): void
    {
        self::assertNull($this->make()->resolveAsset('_collections/banners/sale/item.txt'));
    }

    public function testServesRealPageAsset(): void
    {
        $asset = $this->make()->resolveAsset('about/photo.png');
        self::assertNotNull($asset);
        self::assertSame('image/png', $asset['mime']);
    }

    public function testServesRealCollectionAsset(): void
    {
        $asset = $this->make()->resolveAsset('_collections/banners/sale/bg.png');
        self::assertNotNull($asset);
        self::assertSame('image/png', $asset['mime']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\MediaService;
use Station0\Service\Page;
use Station0\Service\PageRenderer;
use Station0\Service\ThumbService;
use Station0\Tests\Support\BlockFixtures;

#[RequiresPhpExtension('gd')]
final class ThumbServiceTest extends TestCase
{
    private string $root;
    private string $pagesDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->root     = sys_get_temp_dir() . '/station0-thumb-' . bin2hex(random_bytes(4));
        $this->pagesDir = $this->root . '/pages';
        $this->cacheDir = $this->root . '/cache';

        mkdir($this->pagesDir . '/about', 0775, true);
        file_put_contents($this->pagesDir . '/about/page.txt', "Title: About\n---\n\nBody\n");

        $this->jpeg('photo.jpg', 1200, 800);

        $png = imagecreatetruecolor(400, 300);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagefilledrectangle($png, 100, 100, 300, 200, imagecolorallocate($png, 255, 0, 0));
        imagepng($png, $this->pagesDir . '/about/logo.png');

        imagegif(imagecreatetruecolor(800, 600), $this->pagesDir . '/about/anim.gif');
        file_put_contents($this->pagesDir . '/about/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function thumbs(?string $publicDir = null, ?string $format = null): ThumbService
    {
        $media = new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir, $this->root . '/collections');
        return new ThumbService($media, $this->cacheDir, 'test-secret', $publicDir, $format);
    }

    /** Resolve a thumb URL produced by url() the way the /thumb route does. */
    private function serve(ThumbService $thumbs, string $url): ?array
    {
        self::assertMatchesRegularExpression('#^/thumb/[^/]+/[a-f0-9]{12}/.+#', $url);
        [, , $spec, $sig, $path] = explode('/', $url, 5);
        return $thumbs->resolve($spec, $sig, $path);
    }

    public function testWidthThumbnailKeepsAspectRatio(): void
    {
        $thumbs = $this->thumbs();
        $url    = $thumbs->url('/media/about/photo.jpg', 600);
        self::assertStringStartsWith('/thumb/600x0/', $url);
        self::assertStringEndsWith('/about/photo.jpg', $url);

        $res = $this->serve($thumbs, $url);
        self::assertNotNull($res);
        self::assertFalse($res['fallback']);
        self::assertSame('image/jpeg', $res['mime']);
        self::assertStringStartsWith($this->cacheDir . '/thumbs/', $res['path']);
        self::assertSame([600, 400], array_slice(getimagesize($res['path']), 0, 2));
    }

    public function testCoverCropsToExactBox(): void
    {
        $thumbs = $this->thumbs();
        $url    = $thumbs->url('/media/about/photo.jpg', 300, 300, 'cover');
        self::assertStringStartsWith('/thumb/300x300-c/', $url);
        self::assertSame([300, 300], array_slice(getimagesize($this->serve($thumbs, $url)['path']), 0, 2));
    }

    public function testBoxWithoutCoverFitsInside(): void
    {
        $thumbs = $this->thumbs();
        $res    = $this->serve($thumbs, $thumbs->url('/media/about/photo.jpg', 300, 300));
        self::assertSame([300, 200], array_slice(getimagesize($res['path']), 0, 2));
    }

    public function testSecondRequestReusesCachedFile(): void
    {
        $thumbs = $this->thumbs();
        $url    = $thumbs->url('/media/about/photo.jpg', 600);
        $first  = $this->serve($thumbs, $url)['path'];
        touch($first, time() - 100);
        clearstatcache();
        self::assertSame($first, $this->serve($thumbs, $url)['path']);
        self::assertSame(time() - 100, filemtime($first));
    }

    public function testPngKeepsTransparency(): void
    {
        $thumbs = $this->thumbs();
        $res    = $this->serve($thumbs, $thumbs->url('/media/about/logo.png', 200));
        self::assertSame('image/png', $res['mime']);
        $img = imagecreatefrompng($res['path']);
        self::assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 0x7F);  // corner stays transparent
        self::assertSame(0, (imagecolorat($img, 100, 75) >> 24) & 0x7F); // centre stays opaque
    }

    public function testNeverUpscales(): void
    {
        $thumbs = $this->thumbs();
        self::assertSame('/media/about/photo.jpg', $thumbs->url('/media/about/photo.jpg', 1200));
        self::assertSame('/media/about/photo.jpg', $thumbs->url('/media/about/photo.jpg', 2000));
        self::assertSame('/media/about/photo.jpg', $thumbs->url('/media/about/photo.jpg', 2000, 2000));
        // A cover box larger than the source still crops to its aspect ratio,
        // but the output never exceeds the source pixels.
        $res = $this->serve($thumbs, $thumbs->url('/media/about/photo.jpg', 2000, 2000, 'cover'));
        self::assertSame([800, 800], array_slice(getimagesize($res['path']), 0, 2));
        $res = $this->serve($thumbs, $thumbs->url('/media/about/photo.jpg', 2000, 1000, 'cover'));
        self::assertSame([1200, 600], array_slice(getimagesize($res['path']), 0, 2));
    }

    public function testUnresizableSourcesPassThrough(): void
    {
        $thumbs = $this->thumbs();
        foreach ([
            'https://example.com/a.jpg',
            '/media/about/icon.svg',
            '/media/about/anim.gif',
            '/media/about/missing.jpg',
            '/media/about/page.txt',
            '/uploads/legacy.jpg',
            '/media/about/photo.jpg?x=1',
            '',
        ] as $src) {
            self::assertSame($src, $thumbs->url($src, 300), $src);
        }
        self::assertSame('', $thumbs->url(null, 300));
        self::assertSame('/media/about/photo.jpg', $thumbs->url('/media/about/photo.jpg', 0));
    }

    public function testSrcsetStopsAtOriginalWidth(): void
    {
        $thumbs = $this->thumbs();
        $set    = $thumbs->srcset('/media/about/photo.jpg', [1600, 400, 800, 400, 2000]);
        $parts  = explode(', ', $set);
        self::assertCount(3, $parts);
        self::assertMatchesRegularExpression('#^/thumb/400x0/[a-f0-9]{12}/about/photo\.jpg 400w$#', $parts[0]);
        self::assertMatchesRegularExpression('#^/thumb/800x0/[a-f0-9]{12}/about/photo\.jpg 800w$#', $parts[1]);
        self::assertSame('/media/about/photo.jpg 1200w', $parts[2]);
        self::assertSame('', $thumbs->srcset('/media/about/icon.svg', [400]));
    }

    public function testRejectsForgedOrMalformedRequests(): void
    {
        $thumbs = $this->thumbs();
        $url    = $thumbs->url('/media/about/photo.jpg', 600);
        [, , $spec, $sig, $path] = explode('/', $url, 5);

        self::assertNull($thumbs->resolve('601x0', $sig, $path));            // spec not signed
        self::assertNull($thumbs->resolve($spec, 'aaaaaaaaaaaa', $path));    // wrong signature
        self::assertNull($thumbs->resolve($spec, $sig, 'about/logo.png'));  // path not signed
        self::assertNull($thumbs->resolve('9999x0', $sig, $path));           // over MAX_SIZE
        self::assertNull($thumbs->resolve('0x0', $sig, $path));
        self::assertNull($thumbs->resolve('600x0-c', $sig, $path));          // cover needs both sides
        self::assertNull($thumbs->resolve($spec, $sig, '../about/photo.jpg'));
        self::assertNull($thumbs->resolve($spec, $sig, 'about/page.txt'));

        $other = new ThumbService(
            new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir),
            $this->cacheDir,
            'another-secret',
        );
        self::assertNull($other->resolve($spec, $sig, $path));
    }

    public function testReplacingTheSourceChangesTheUrl(): void
    {
        $thumbs = $this->thumbs();
        $before = $thumbs->url('/media/about/photo.jpg', 600);
        touch($this->pagesDir . '/about/photo.jpg', time() + 60);
        clearstatcache();
        $after = $thumbs->url('/media/about/photo.jpg', 600);

        self::assertNotSame($before, $after);
        self::assertNull($this->serve($thumbs, $before));
        self::assertNotNull($this->serve($thumbs, $after));
    }

    #[RequiresPhpExtension('exif')]
    public function testExifOrientationIsApplied(): void
    {
        // Stored 400×200 but tagged "rotate 90° CW" → displayed 200×400.
        $this->jpeg('portrait.jpg', 400, 200, orientation: 6);
        $thumbs = $this->thumbs();

        self::assertSame('/media/about/portrait.jpg', $thumbs->url('/media/about/portrait.jpg', 200));
        $res = $this->serve($thumbs, $thumbs->url('/media/about/portrait.jpg', 100));
        self::assertSame([100, 200], array_slice(getimagesize($res['path']), 0, 2));
    }

    public function testPlan(): void
    {
        self::assertSame(
            ['sx' => 0, 'sy' => 0, 'sw' => 1200, 'sh' => 800, 'dw' => 600, 'dh' => 400],
            ThumbService::plan(1200, 800, 600, 0, false),
        );
        self::assertSame(
            ['sx' => 0, 'sy' => 0, 'sw' => 1200, 'sh' => 800, 'dw' => 300, 'dh' => 200],
            ThumbService::plan(1200, 800, 0, 200, false),
        );
        self::assertSame(
            ['sx' => 200, 'sy' => 0, 'sw' => 800, 'sh' => 800, 'dw' => 100, 'dh' => 100],
            ThumbService::plan(1200, 800, 100, 100, true),
        );
    }

    public function testPurgeRemovesGeneratedFiles(): void
    {
        $thumbs = $this->thumbs();
        $this->serve($thumbs, $thumbs->url('/media/about/photo.jpg', 600));
        $this->serve($thumbs, $thumbs->url('/media/about/photo.jpg', 300));

        self::assertSame(2, ThumbService::purge($this->cacheDir));
        self::assertDirectoryDoesNotExist($this->cacheDir . '/thumbs');
        self::assertSame(0, ThumbService::purge($this->cacheDir));
    }

    public function testKeyIsCreatedOnceAndReused(): void
    {
        $file = $this->root . '/writable/thumbs.key';
        $key  = ThumbService::loadOrCreateKey($file);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        self::assertSame($key, ThumbService::loadOrCreateKey($file));
    }

    public function testWebpFormatConvertsAndKeepsRealExtension(): void
    {
        $thumbs = $this->thumbs();
        $url    = $thumbs->url('/media/about/photo.jpg', 600, format: 'webp');
        self::assertMatchesRegularExpression('#^/thumb/600x0-webp/[a-f0-9]{12}/about/photo\.jpg\.webp$#', $url);

        $res = $this->serve($thumbs, $url);
        self::assertSame('image/webp', $res['mime']);
        self::assertStringEndsWith('.webp', $res['path']);
        self::assertSame([600, 400, IMAGETYPE_WEBP], array_slice(getimagesize($res['path']), 0, 3));

        // The suffix is part of the contract: without it the request is rejected.
        [, , $spec, $sig, $path] = explode('/', $url, 5);
        self::assertNull($thumbs->resolve($spec, $sig, substr($path, 0, -5)));
    }

    public function testWebpConvertsEvenWithoutResizing(): void
    {
        $thumbs = $this->thumbs(format: 'webp');   // configured default
        $url    = $thumbs->url('/media/about/photo.jpg', 2000);
        self::assertStringStartsWith('/thumb/2000x0-webp/', $url);
        self::assertSame([1200, 800], array_slice(getimagesize($this->serve($thumbs, $url)['path']), 0, 2));

        // 'original' overrides the default; a WebP source is never "converted".
        self::assertSame('/media/about/photo.jpg', $thumbs->url('/media/about/photo.jpg', 2000, format: 'original'));
        imagewebp(imagecreatetruecolor(300, 200), $this->pagesDir . '/about/pic.webp');
        self::assertStringStartsWith('/thumb/100x0/', $thumbs->url('/media/about/pic.webp', 100));
    }

    public function testSrcsetHonoursFormat(): void
    {
        $set = $this->thumbs()->srcset('/media/about/photo.jpg', [600, 2000], 'webp');
        self::assertMatchesRegularExpression(
            '#^/thumb/600x0-webp/\w+/about/photo\.jpg\.webp 600w, /thumb/1200x0-webp/\w+/about/photo\.jpg\.webp 1200w$#',
            $set,
        );
    }

    public function testStaticModeWritesFileAtItsUrlPath(): void
    {
        $public = $this->root . '/public';
        $thumbs = $this->thumbs($public);
        $url    = $thumbs->url('/media/about/photo.jpg', 600);
        $res    = $this->serve($thumbs, $url);

        self::assertSame($public . $url, $res['path']);
        self::assertFileExists($public . $url);
        self::assertDirectoryDoesNotExist($this->cacheDir . '/thumbs');

        $webp = $thumbs->url('/media/about/photo.jpg', 300, format: 'webp');
        self::assertSame($public . $webp, $this->serve($thumbs, $webp)['path']);

        self::assertSame(2, ThumbService::purge($this->cacheDir, $public));
        self::assertDirectoryDoesNotExist($public . '/thumb');
    }

    public function testMarkdownImagesGetThumbnails(): void
    {
        $fx = new BlockFixtures();
        try {
            $env = new Environment(['html_input' => 'escape']);
            $env->addExtension(new CommonMarkCoreExtension());
            $renderer = new PageRenderer(
                new MarkdownConverter($env),
                $fx->registry(),
                new FileCache($this->cacheDir . '/pages'),
                new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir),
                $this->thumbs(),
                500,
            );
            $page = new Page(slug: 'about', title: 'About', body: "![Team](photo.jpg)\n\n![Logo](icon.svg)\n\n![Ext](https://example.com/x.jpg)\n");
            $page->urlPath  = '/about';
            $page->filePath = $this->pagesDir . '/about/page.txt';
            $html = $renderer->render($page, 1);

            self::assertMatchesRegularExpression(
                '#<img src="(/thumb/500x0/\w+/about/photo\.jpg)" srcset="\1 1x, /thumb/1000x0/\w+/about/photo\.jpg 2x" loading="lazy" alt="Team" />#',
                $html,
            );
            self::assertStringContainsString('<img src="/media/about/icon.svg" alt="Logo" />', $html);
            self::assertStringContainsString('<img src="https://example.com/x.jpg" alt="Ext" />', $html);
        } finally {
            $fx->cleanup();
        }
    }

    private function jpeg(string $name, int $w, int $h, int $orientation = 1): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 40, 120, 200));
        ob_start();
        imagejpeg($img);
        $data = (string) ob_get_clean();

        if ($orientation !== 1) {
            // Minimal little-endian EXIF block: one IFD0 entry, Orientation (0x0112), SHORT.
            $tiff = 'II' . pack('v', 42) . pack('V', 8)
                . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
                . pack('V', 0);
            $app1 = "Exif\0\0" . $tiff;
            $data = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($data, 2);
        }
        file_put_contents($this->pagesDir . '/about/' . $name, $data);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

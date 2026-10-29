<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/** Response headers of /media assets — the SVG sandbox must not get lost in a refactor. */
final class MediaHeadersAppTest extends TestCase
{
    use BootsApp;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>';

    /** @return iterable<string, array{string}> */
    public static function svgPaths(): iterable
    {
        yield 'page asset'       => ['/media/about/logo.svg'];
        yield 'root page asset'  => ['/media/~/logo.svg'];
        yield 'collection asset' => ['/media/_collections/products/chair/logo.svg'];
    }

    /** @dataProvider svgPaths */
    public function testSvgIsServedInlineInASandbox(string $path): void
    {
        $app = $this->boot([], null, null, function (TestSite $site): void {
            foreach (['site/content/pages/about', 'site/content/pages', 'site/content/collections/products/chair'] as $dir) {
                $site->write($dir . '/logo.svg', self::SVG);
            }
        });

        $response = $this->get($app, $path);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($response->hasHeader('Content-Disposition'));
        self::assertSame(self::SVG, (string) $response->getBody());
    }

    public function testRasterImageHasNoSandboxAndDocumentDownloads(): void
    {
        $app = $this->boot([], null, null, function (TestSite $site): void {
            $site->write('site/content/pages/about/photo.png', "\x89PNG\r\n\x1a\n");
            $site->write('site/content/pages/about/price list.pdf', "%PDF-1.4\n%%EOF\n");
        });

        $png = $this->get($app, '/media/about/photo.png');
        self::assertSame('image/png', $png->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $png->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($png->hasHeader('Content-Security-Policy'));
        self::assertFalse($png->hasHeader('Content-Disposition'));

        $pdf = $this->get($app, '/media/about/price%20list.pdf');
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $pdf->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment; filename="price list.pdf"', $pdf->getHeaderLine('Content-Disposition'));
    }
}

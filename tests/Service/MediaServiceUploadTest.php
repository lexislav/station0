<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;
use Station0\Service\ContentRepository;
use Station0\Service\MediaService;

/**
 * Documents keep their client extension on disk, so the extension itself must
 * be on the allow-list — a sniffed MIME that is allowed must not carry `.php`
 * (or any other extension) through.
 */
final class MediaServiceUploadTest extends TestCase
{
    private string $root;
    private string $pagesDir;
    private string $collectionsDir;

    protected function setUp(): void
    {
        $this->root           = sys_get_temp_dir() . '/s0-upload-' . bin2hex(random_bytes(6));
        $this->pagesDir       = $this->root . '/pages';
        $this->collectionsDir = $this->root . '/collections';
        mkdir($this->pagesDir . '/about', 0775, true);
        mkdir($this->collectionsDir . '/banners/sale', 0775, true);
        file_put_contents($this->pagesDir . '/about/page.txt', "Title: About\n---\nAbout\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function make(): MediaService
    {
        return new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir, $this->collectionsDir);
    }

    private function upload(string $clientName, string $contents): UploadedFile
    {
        $tmp = $this->root . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents);
        return new UploadedFile($tmp, $clientName, null, strlen($contents), UPLOAD_ERR_OK);
    }

    /** @return iterable<string, array{string, string}> */
    public static function disallowedExtensions(): iterable
    {
        yield 'pdf polyglot as .php'  => ['shell.php', "%PDF-1.4\n<?php echo 1; ?>\n"];
        yield 'plain text as .php'    => ['shell.php', "hello\n<?php echo 1;\n"];
        yield 'plain text as .phtml'  => ['shell.phtml', "hello\n"];
        yield 'plain text as .html'   => ['page.html', "hello\n"];
        yield 'no extension'          => ['README', "hello\n"];
    }

    /** @dataProvider disallowedExtensions */
    public function testPageUploadRejectsExtensionNotOnAllowList(string $name, string $contents): void
    {
        try {
            $this->make()->storeForPage('/about', $this->upload($name, $contents));
            self::fail('Upload of ' . $name . ' was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Unsupported file type', $e->getMessage());
        }
        self::assertSame(['page.txt'], array_values(array_diff(scandir($this->pagesDir . '/about'), ['.', '..'])));
    }

    /** @dataProvider disallowedExtensions */
    public function testCollectionUploadRejectsExtensionNotOnAllowList(string $name, string $contents): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type');
        $this->make()->storeForCollection('banners', 'sale', $this->upload($name, $contents));
    }

    public function testAllowedDocumentIsStoredWithItsExtension(): void
    {
        $result = $this->make()->storeForPage('/about', $this->upload('Price List.pdf', "%PDF-1.4\n%%EOF\n"));
        self::assertSame('price-list.pdf', $result['filename']);
        self::assertFileExists($this->pagesDir . '/about/price-list.pdf');
    }

    public function testAllowedDocumentWithUnlistedSniffedMimeIsAccepted(): void
    {
        // Office Open XML sniffs as application/zip; the extension decides.
        $result = $this->make()->storeForPage('/about', $this->upload('report.docx', "PK\x03\x04" . str_repeat("\0", 64)));
        self::assertSame('report.docx', $result['filename']);
    }
}

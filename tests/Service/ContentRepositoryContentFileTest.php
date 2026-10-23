<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\MediaService;

/**
 * A page directory can hold other `.txt` files besides its content file (an
 * old upload, a note). The content file must not depend on which sorts first.
 */
final class ContentRepositoryContentFileTest extends TestCase
{
    private string $root;
    private string $pagesDir;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->root         = sys_get_temp_dir() . '/s0-contentfile-' . bin2hex(random_bytes(6));
        $this->pagesDir     = $this->root . '/pages';
        $this->templatesDir = $this->root . '/templates';
        mkdir($this->pagesDir . '/about', 0775, true);
        mkdir($this->pagesDir . '/blog', 0775, true);
        mkdir($this->templatesDir, 0775, true);
        foreach (['home', 'page', 'article'] as $template) {
            touch($this->templatesDir . '/' . $template . '.twig');
        }

        file_put_contents($this->pagesDir . '/home.txt', "Title: Home\n---\nHome\n");
        file_put_contents($this->pagesDir . '/about/page.txt', "Title: About\n---\nAbout\n");
        file_put_contents($this->pagesDir . '/blog/article.txt', "Title: Blog\n---\nBlog\n");

        // Stray text files that sort before the real content file.
        file_put_contents($this->pagesDir . '/a-notes.txt', "Uploaded notes\n");
        file_put_contents($this->pagesDir . '/about/a.txt', "Uploaded notes\n");
        file_put_contents($this->pagesDir . '/blog/a.txt', "Uploaded notes\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function repo(?Logger $logger = null): ContentRepository
    {
        return new ContentRepository($this->pagesDir, $this->templatesDir, $logger);
    }

    public function testFindPrefersTheFileNamedAfterATemplate(): void
    {
        $repo = $this->repo();

        $about = $repo->find('/about');
        self::assertSame('About', $about?->title);
        self::assertSame('page', $about->template);

        $blog = $repo->find('/blog');
        self::assertSame('Blog', $blog?->title);
        self::assertSame('article', $blog->template);
    }

    public function testRootPageIgnoresStrayTextFiles(): void
    {
        $repo = $this->repo();
        self::assertSame('Home', $repo->find('/')?->title);

        $root = array_values(array_filter($repo->all(), fn ($p) => $p->urlPath === '/'));
        self::assertCount(1, $root);
        self::assertSame('Home', $root[0]->title);
    }

    public function testScanUsesTheSameContentFiles(): void
    {
        $titles = [];
        foreach ($this->repo()->all() as $page) {
            $titles[$page->urlPath] = $page->title;
        }
        self::assertSame('About', $titles['/about'] ?? null);
        self::assertSame('Blog', $titles['/blog'] ?? null);
    }

    public function testWithoutTemplatesDirPageTxtWins(): void
    {
        $page = (new ContentRepository($this->pagesDir))->find('/about');
        self::assertSame('About', $page?->title);
    }

    public function testAmbiguousDirectoryIsLoggedOnce(): void
    {
        $handler = new TestHandler();
        $repo    = $this->repo(new Logger('test', [$handler]));
        $repo->find('/about');
        $repo->find('/about');

        $about = array_filter(
            $handler->getRecords(),
            fn ($r) => str_ends_with((string) ($r->context['dir'] ?? ''), '/about'),
        );
        self::assertCount(1, $about);
        self::assertStringContainsString('page.txt', array_values($about)[0]->message);
    }

    public function testSingleContentFileIsNotLogged(): void
    {
        unlink($this->pagesDir . '/about/a.txt');
        $handler = new TestHandler();
        $this->repo(new Logger('test', [$handler]))->find('/about');
        self::assertFalse($handler->hasWarningRecords());
    }

    public function testRealContentFileIsNotServedAsMedia(): void
    {
        $media = new MediaService($this->repo(), $this->pagesDir, $this->root . '/collections');
        self::assertNull($media->resolveAsset('about/page.txt'));
        self::assertNotNull($media->resolveAsset('about/a.txt'));
    }
}

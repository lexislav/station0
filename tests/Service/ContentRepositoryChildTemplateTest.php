<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;

final class ContentRepositoryChildTemplateTest extends TestCase
{
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/s0-childtpl-' . bin2hex(random_bytes(6));
        @mkdir($this->pagesDir, 0775, true);
        $this->addPage('/', 'page', 'Home');
        // A stream-like section whose children must be galleries; `gallery`
        // is used nowhere else, so it is a child-only template.
        $this->addPage('/fotogalerie', 'page', 'Fotogalerie', "AllowedChildTemplates: gallery\n");
        $this->addPage('/fotogalerie/summer', 'gallery', 'Summer');
        $this->addPage('/about', 'page', 'About');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->pagesDir));
    }

    /** @param string $path URL path, '/' = home */
    private function addPage(string $path, string $template, string $title, string $frontMatter = ''): void
    {
        $dir = rtrim($this->pagesDir . $path, '/');
        @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/{$template}.txt", "Title: {$title}\n{$frontMatter}---\nBody\n");
    }

    private function repo(): ContentRepository
    {
        return new ContentRepository($this->pagesDir);
    }

    public function testChildOnlyTemplateIsRejectedUnderUnrestrictedParent(): void
    {
        $repo = $this->repo();
        self::assertSame(['gallery'], $repo->childOnlyTemplates());

        self::assertFalse($repo->templateAllowedUnder($repo->find('/'), 'gallery'));
        self::assertFalse($repo->templateAllowedUnder($repo->find('/about'), 'gallery'));
        // The root page has no parent.
        self::assertFalse($repo->templateAllowedUnder(null, 'gallery'));
    }

    public function testChildOnlyTemplateIsAcceptedUnderSanctioningParent(): void
    {
        $repo   = $this->repo();
        $parent = $repo->find('/fotogalerie');

        // A new child, and re-saving the existing one with its own template.
        self::assertTrue($repo->templateAllowedUnder($parent, 'gallery'));
        $summer = $repo->find('/fotogalerie/summer');
        self::assertSame('gallery', $summer->template);
        self::assertTrue($repo->templateAllowedUnder($repo->find('/fotogalerie'), $summer->template));
    }

    public function testOrdinaryTemplatesAreNotAffected(): void
    {
        $repo = $this->repo();

        self::assertTrue($repo->templateAllowedUnder($repo->find('/about'), 'page'));
        self::assertTrue($repo->templateAllowedUnder(null, 'page'));
        // Parent restrictions on ordinary templates are assertChildTemplateAllowed()'s job.
        self::assertTrue($repo->templateAllowedUnder($repo->find('/fotogalerie'), 'page'));
    }

    public function testTemplateUsedOutsideRestrictionIsNoLongerChildOnly(): void
    {
        $this->addPage('/about/pics', 'gallery', 'Pics');
        $repo = $this->repo();

        self::assertSame([], $repo->childOnlyTemplates());
        self::assertTrue($repo->templateAllowedUnder($repo->find('/'), 'gallery'));
    }
}

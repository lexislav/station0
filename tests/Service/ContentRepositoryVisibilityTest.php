<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\Page;
use Station0\Support\FrontMatter;
use Station0\Support\Visibility as V;

final class ContentRepositoryVisibilityTest extends TestCase
{
    private const PAST   = '2000-01-01 00:00';
    private const FUTURE = '2099-01-01 00:00';

    private string $pagesDir;

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/s0-visibility-' . bin2hex(random_bytes(6));
        @mkdir($this->pagesDir, 0775, true);
        $this->addPage('/', 'Home');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->pagesDir));
    }

    /** @param string $path URL path, '/' = home */
    private function addPage(string $path, string $title, string $frontMatter = ''): void
    {
        $dir = rtrim($this->pagesDir . $path, '/');
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/page.txt', "Title: {$title}\n{$frontMatter}---\nBody\n");
    }

    private function repo(): ContentRepository
    {
        return new ContentRepository($this->pagesDir);
    }

    /** @param list<Page> $pages @return list<string> */
    private static function paths(array $pages): array
    {
        return array_map(fn (Page $p) => $p->urlPath, $pages);
    }

    // ── Backward compatibility ──

    public function testLegacyFrontMatterKeepsWorking(): void
    {
        $this->addPage('/plain', 'Plain');
        $this->addPage('/off', 'Off', "Published: false\n");
        $this->addPage('/soon', 'Soon', "Published: true\nPublishedAt: " . self::FUTURE . "\n");
        $this->addPage('/old', 'Old', "Published: true\nPublishedAt: " . self::PAST . "\n");

        $repo = $this->repo();
        self::assertSame(V::LIVE, $repo->find('/plain')->state());
        self::assertSame(V::DRAFT, $repo->find('/off')->state());
        self::assertSame(V::SCHEDULED, $repo->find('/soon')->state());
        self::assertSame(V::LIVE, $repo->find('/old')->state());
        self::assertSame(['/', '/old', '/plain'], self::paths($repo->all(false)));

        // Legacy property access still works.
        $off = $repo->find('/off');
        self::assertFalse($off->published);
        $off->published = true;
        self::assertSame(V::PUBLISHED, $off->status);
    }

    public function testStatusWinsOverLegacyFlagAndPublishAtOverridesPublishedAt(): void
    {
        $this->addPage('/a', 'A', "Status: archived\nPublished: true\n");
        $this->addPage('/b', 'B', "Status: published\nPublishAt: " . self::PAST . "\nPublishedAt: " . self::FUTURE . "\n");

        $repo = $this->repo();
        self::assertSame(V::ARCHIVED, $repo->find('/a')->state());
        self::assertSame(V::LIVE, $repo->find('/b')->state());
        self::assertSame(self::FUTURE, $repo->find('/b')->publishedAt);
    }

    public function testNewKeysStayOutOfExtra(): void
    {
        $this->addPage('/x', 'X', "Status: published\nPublishAt: " . self::PAST . "\nExpireAt: " . self::FUTURE . "\nListing: unlisted\nColor: red\n");

        $page = $this->repo()->find('/x');
        self::assertSame(['color' => 'red'], $page->extra);
        self::assertSame(self::PAST, $page->publishAt);
        self::assertSame(self::FUTURE, $page->expireAt);
        self::assertSame(V::UNLISTED, $page->listing);
    }

    // ── Inheritance ──

    public function testDraftParentHidesItsSubtree(): void
    {
        $this->addPage('/alba', 'Alba', "Status: draft\n");
        $this->addPage('/alba/dojak', 'Dojak');
        $this->addPage('/alba/dojak/photo', 'Photo');

        $repo  = $this->repo();
        $child = $repo->find('/alba/dojak');
        self::assertSame(V::LIVE, $child->ownState());
        self::assertSame(V::HIDDEN, $child->state());
        self::assertFalse($child->isLive());
        self::assertSame('/alba', $child->blockingAncestor()->urlPath);
        self::assertSame(404, $child->httpStatus());
        self::assertFalse($repo->find('/alba/dojak/photo')->isLive());

        self::assertSame(['/'], self::paths($repo->all(false)));
        self::assertSame([], $repo->children('/alba'));
    }

    public function testGoneAncestorMakesTheSubtreeGone(): void
    {
        $this->addPage('/old', 'Old', "Status: archived\n");
        $this->addPage('/old/post', 'Post');
        $this->addPage('/exp', 'Exp', "ExpireAt: " . self::PAST . "\n");
        $this->addPage('/exp/post', 'Post');

        $repo = $this->repo();
        self::assertSame(410, $repo->find('/old')->httpStatus());
        self::assertSame(410, $repo->find('/old/post')->httpStatus());
        self::assertSame(410, $repo->find('/exp/post')->httpStatus());
    }

    public function testOutermostBlockerIsReported(): void
    {
        $this->addPage('/a', 'A', "Status: archived\n");
        $this->addPage('/a/b', 'B', "Status: published\nPublishAt: " . self::FUTURE . "\n");
        $this->addPage('/a/b/c', 'C');

        self::assertSame('/a', $this->repo()->find('/a/b/c')->blockingAncestor()->urlPath);
    }

    public function testDraftRootDoesNotHideTheSite(): void
    {
        $this->addPage('/', 'Home', "Status: draft\n");
        $this->addPage('/about', 'About');

        $repo = $this->repo();
        self::assertFalse($repo->find('/')->isLive());
        self::assertTrue($repo->find('/about')->isLive());
        self::assertSame(['/about'], self::paths($repo->all(false)));
    }

    public function testInheritanceIsEvaluatedAtTheGivenTime(): void
    {
        $this->addPage('/event', 'Event', "PublishAt: 2026-10-10 10:00\n");
        $this->addPage('/event/program', 'Program');

        $child = $this->repo()->find('/event/program');
        self::assertFalse($child->isLive((int) strtotime('2026-10-10 09:59')));
        self::assertTrue($child->isLive((int) strtotime('2026-10-10 10:00')));
    }

    // ── Listing ──

    public function testChildrenAndNavChildren(): void
    {
        $this->addPage('/a', 'A', "Sort: 1\n");
        $this->addPage('/b', 'B', "Sort: 2\nListing: nav-hidden\n");
        $this->addPage('/c', 'C', "Sort: 3\nListing: unlisted\n");
        $this->addPage('/d', 'D', "Sort: 4\nStatus: draft\n");
        $this->addPage('/a/x', 'X');

        $repo = $this->repo();
        self::assertSame(['/a', '/b'], self::paths($repo->children('/')));
        self::assertSame(['/a', '/b', '/c'], self::paths($repo->children('/', true)));
        self::assertSame(['/a'], self::paths($repo->navChildren('/')));
        self::assertSame(['/a/x'], self::paths($repo->children('a')));

        $c = $repo->find('/c');
        self::assertTrue($c->isLive());
        self::assertFalse($c->isListed());
        self::assertFalse($c->inNav());
    }

    public function testListingIsNotInherited(): void
    {
        $this->addPage('/campaign', 'Campaign', "Listing: unlisted\n");
        $this->addPage('/campaign/step', 'Step');

        self::assertSame(['/campaign/step'], self::paths($this->repo()->children('/campaign')));
    }

    // ── Save round-trip ──

    public function testSaveRoundTrip(): void
    {
        $this->addPage('/rt', 'RT');
        $repo = $this->repo();
        $page = $repo->find('/rt');
        $page->status    = V::ARCHIVED;
        $page->publishAt = '2026-01-01 08:00';
        $page->expireAt  = '2026-12-31 23:59';
        $page->listing   = V::NAV_HIDDEN;
        $repo->save($page);

        [$meta] = FrontMatter::parse((string) file_get_contents($page->filePath));
        self::assertSame('archived', $meta['status']);
        self::assertSame('false', $meta['published'], 'Published mirrors Status for pre-0.9 readers');
        self::assertSame('2026-01-01 08:00', $meta['publishat']);
        self::assertSame('2026-12-31 23:59', $meta['expireat']);
        self::assertSame('nav-hidden', $meta['listing']);

        $again = $this->repo()->find('/rt');
        self::assertSame(V::ARCHIVED, $again->status);
        self::assertSame(V::NAV_HIDDEN, $again->listing);
        self::assertSame([], $again->extra);
    }

    public function testDefaultsAreNotWritten(): void
    {
        $this->addPage('/rt', 'RT');
        $repo = $this->repo();
        $repo->save($repo->find('/rt'));

        [$meta] = FrontMatter::parse((string) file_get_contents($this->pagesDir . '/rt/page.txt'));
        self::assertSame('published', $meta['status']);
        self::assertSame('true', $meta['published']);
        self::assertArrayNotHasKey('listing', $meta);
        self::assertArrayNotHasKey('publishat', $meta);
        self::assertArrayNotHasKey('expireat', $meta);
    }
}

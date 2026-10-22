<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;

/**
 * URL segments map to directory names: `.` / `..` must never let find() — and
 * therefore delete() / update() in the admin — reach files outside the pages tree.
 */
final class ContentRepositoryPathTest extends TestCase
{
    private string $root;
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->root     = sys_get_temp_dir() . '/s0-paths-' . bin2hex(random_bytes(6));
        $this->pagesDir = $this->root . '/content/pages';
        mkdir($this->pagesDir . '/about', 0775, true);
        file_put_contents($this->pagesDir . '/home.txt', "Title: Home\n---\nHome\n");
        file_put_contents($this->pagesDir . '/about/page.txt', "Title: About\n---\nAbout\n");

        // Text files one and two levels above the pages tree, plus a sibling
        // that delete() would wipe along with them.
        file_put_contents($this->root . '/content/notes.txt', "Title: Notes\n---\nOutside\n");
        file_put_contents($this->root . '/secret.txt', "Title: Secret\n---\nOutside\n");
        file_put_contents($this->root . '/content/keep.json', '{}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function repo(): ContentRepository
    {
        return new ContentRepository($this->pagesDir);
    }

    /** @return iterable<string, array{string}> */
    public static function escapingPaths(): iterable
    {
        yield 'parent'              => ['/..'];
        yield 'grandparent'         => ['/../..'];
        yield 'via a real page'     => ['/about/../..'];
        yield 'current dir'         => ['/.'];
        yield 'backslash'           => ['/..\\..'];
        yield 'NUL byte'            => ["/ab\0out"];   // would throw ValueError in is_dir()
    }

    /** @dataProvider escapingPaths */
    public function testFindRejectsEscapingSegments(string $path): void
    {
        self::assertNull($this->repo()->find($path));
    }

    public function testDeleteCannotReachOutsideThePagesTree(): void
    {
        self::assertFalse($this->repo()->delete('/..'));
        self::assertFileExists($this->root . '/content/notes.txt');
        self::assertFileExists($this->root . '/content/keep.json');
    }

    public function testRegularPathsStillResolve(): void
    {
        self::assertSame('About', $this->repo()->find('/about')?->title);
        self::assertSame('About', $this->repo()->find('about/')?->title);
        self::assertSame('Home', $this->repo()->find('/')?->title);
    }
}

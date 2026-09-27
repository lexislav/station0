<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\NavGroups;

final class NavGroupsTest extends TestCase
{
    private string $root;
    private string $dir;
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->root     = sys_get_temp_dir() . '/s0-groups-' . bin2hex(random_bytes(6));
        $this->dir      = $this->root . '/collections';
        $this->pagesDir = $this->root . '/pages';
        @mkdir($this->dir, 0775, true);
        @mkdir($this->pagesDir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param string $path URL path, '/' = home */
    private function addPage(string $path, string $title, string $frontMatter = ''): void
    {
        $dir = rtrim($this->pagesDir . $path, '/');
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/page.txt', "Title: {$title}\n{$frontMatter}---\nBody\n");
    }

    private function addCollection(string $name, string $yaml = ''): void
    {
        @mkdir($this->dir . '/' . $name, 0775, true);
        if ($yaml !== '') {
            file_put_contents($this->dir . '/' . $name . '/_collection.yaml', $yaml);
        }
    }

    /** Legacy location, inside the collections directory. */
    private function central(string $yaml): void
    {
        file_put_contents($this->dir . '/_groups.yaml', $yaml);
    }

    /** @param list<string> $userRoles */
    private function make(?array $userRoles = null): NavGroups
    {
        $hasRole = $userRoles === null ? null : fn (string $r) => in_array($r, $userRoles, true);
        return new NavGroups(
            new CollectionRepository($this->dir),
            $hasRole,
            new ContentRepository($this->pagesDir),
            $this->root . '/_groups.yaml',
        );
    }

    public function testInlineGroupCollectsMembersAndSlugifiesId(): void
    {
        $this->addCollection('products', "label: Products\ngroup: Shop\n");
        $this->addCollection('orders', "group: shop\n");
        $this->addCollection('banners', "label: Banners\n");

        $groups = $this->make()->all();

        self::assertCount(1, $groups);
        self::assertSame('shop', $groups[0]['id']);
        self::assertSame('Shop', $groups[0]['label']);
        self::assertSame(['orders', 'products'], array_column($groups[0]['collections'], 'name'));
    }

    public function testUngroupedCollectionHasNoGroup(): void
    {
        $this->addCollection('banners', "label: Banners\n");
        $this->addCollection('products', "group: Shop\n");

        $g = $this->make();
        self::assertNull($g->groupOfCollection('banners'));
        self::assertSame('shop', $g->groupOfCollection('products')['id']);
    }

    public function testCentralOverridesLabelAddsIconAndKeepsDeclaredOrder(): void
    {
        $this->central("blog:\n  label: Blog\nshop:\n  label: E-shop\n  icon: \"🛒\"\nempty:\n  label: Nothing here\n");
        $this->addCollection('products', "group: Shop\n");
        $this->addCollection('posts', "group: blog\n");
        $this->addCollection('faq', "group: Help\n");

        $groups = $this->make()->all();

        // Central in declared order, then inline-only; empty central group dropped.
        self::assertSame(['blog', 'shop', 'help'], array_column($groups, 'id'));
        self::assertSame('E-shop', $groups[1]['label']);
        self::assertSame('🛒', $groups[1]['icon']);
    }

    public function testRolesRestrictAccessButAdminAlwaysPasses(): void
    {
        $this->central("shop:\n  roles: [editor]\nsecret:\n  roles: admin\n");
        $this->addCollection('products', "group: shop\n");
        $this->addCollection('keys', "group: secret\n");
        $this->addCollection('banners');

        $editor = $this->make(['editor']);
        self::assertSame(['shop'], array_column($editor->visible(), 'id'));
        self::assertTrue($editor->canAccessCollection('products'));
        self::assertFalse($editor->canAccessCollection('keys'));
        self::assertTrue($editor->canAccessCollection('banners'));

        $nobody = $this->make([]);
        self::assertSame([], $nobody->visible());

        $admin = $this->make(['admin']);
        self::assertSame(['shop', 'secret'], array_column($admin->visible(), 'id'));
    }

    public function testNoRoleCheckerMeansUnrestricted(): void
    {
        $this->central("shop:\n  roles: [admin]\n");
        $this->addCollection('products', "group: shop\n");

        self::assertCount(1, $this->make()->visible());
    }

    public function testPageGroupRootsSubtreeAndNearestAncestorWins(): void
    {
        $this->addPage('/', 'Home');
        $this->addPage('/downloads', 'Downloads', "Group: Downloads\n");
        $this->addPage('/downloads/manuals', 'Manuals');
        $this->addPage('/downloads/manuals/old', 'Old', "Group: downloads\n");
        $this->addPage('/downloads/archive', 'Archive', "Group: Archive\n");
        $this->addPage('/about', 'About');

        $g = $this->make();

        self::assertSame(['archive', 'downloads'], array_column($g->all(), 'id'));
        // A member inside its own group's subtree is not listed as another root.
        self::assertSame([['path' => '/downloads', 'title' => 'Downloads']], $g->find('downloads')['pages']);
        self::assertSame('downloads', $g->groupOfPage('/downloads')['id']);
        self::assertSame('downloads', $g->groupOfPage('/downloads/manuals/old')['id']);
        self::assertSame('archive', $g->groupOfPage('/downloads/archive')['id']);
        self::assertNull($g->groupOfPage('/about'));
        self::assertNull($g->groupOfPage('/'));
        self::assertSame('/groups/downloads', $g->path($g->find('downloads')));
    }

    public function testPagesAndCollectionsShareAGroup(): void
    {
        file_put_contents($this->root . '/_groups.yaml', "shop:\n  label: E-shop\n");
        $this->central("shop:\n  label: Ignored\n  icon: x\nlegacy:\n  label: Legacy\n");
        $this->addCollection('products', "group: shop\n");
        $this->addPage('/shop', 'Shop', "Group: Shop\n");
        $this->addCollection('banners', "group: legacy\n");

        $g    = $this->make();
        $shop = $g->find('shop');

        // Site-wide file wins over the legacy one; legacy entries still apply.
        self::assertSame('E-shop', $shop['label']);
        self::assertSame('', $shop['icon']);
        self::assertSame('Legacy', $g->find('legacy')['label']);
        self::assertSame(['products'], array_column($shop['collections'], 'name'));
        self::assertSame(['/shop'], array_column($shop['pages'], 'path'));
        self::assertSame('/groups/shop', $g->path($shop));
        self::assertSame('/collections/banners', $g->path($g->find('legacy')));
    }
}

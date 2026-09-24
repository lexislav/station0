<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionGroups;
use Station0\Service\CollectionRepository;

final class CollectionGroupsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-col-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function addCollection(string $name, string $yaml = ''): void
    {
        @mkdir($this->dir . '/' . $name, 0775, true);
        if ($yaml !== '') {
            file_put_contents($this->dir . '/' . $name . '/_collection.yaml', $yaml);
        }
    }

    private function central(string $yaml): void
    {
        file_put_contents($this->dir . '/_groups.yaml', $yaml);
    }

    /** @param list<string> $userRoles */
    private function make(?array $userRoles = null): CollectionGroups
    {
        $hasRole = $userRoles === null ? null : fn (string $r) => in_array($r, $userRoles, true);
        return new CollectionGroups(new CollectionRepository($this->dir), $hasRole);
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
        self::assertNull($g->groupOf('banners'));
        self::assertSame('shop', $g->groupOf('products')['id']);
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
}

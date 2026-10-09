<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\AccessPolicy;
use Station0\Service\Page;

final class AccessPolicyTest extends TestCase
{
    /** @param array<string, string> $settings urlPath => Access value */
    private function make(array $access, array $settings = []): AccessPolicy
    {
        return new AccessPolicy($access, function (string $urlPath) use ($settings): ?Page {
            if (!array_key_exists($urlPath, $settings)) {
                return null;
            }
            return new Page(slug: basename($urlPath), title: $urlPath, body: '', extra: ['access' => $settings[$urlPath]]);
        });
    }

    public function testPublicModeGatesNothingByDefault(): void
    {
        $policy = $this->make([]);
        self::assertSame('public', $policy->mode());
        self::assertFalse($policy->pageRequiresMember('/blog/post'));
        self::assertFalse($policy->mediaRequiresMember('blog/photo.jpg'));
        self::assertNull($policy->loginPath());
    }

    public function testMembersModeWithPublicPathsRedirectAndLogin(): void
    {
        $policy = $this->make([
            'mode'      => 'members',
            'public'    => ['/', '/info/*', 'kontakt'],
            'redirect'  => '/vitejte',
            'loginPath' => '/prihlaseni/',
        ]);
        self::assertFalse($policy->pageRequiresMember('/'));
        self::assertFalse($policy->pageRequiresMember('/kontakt'));
        self::assertFalse($policy->pageRequiresMember('/info'));
        self::assertFalse($policy->pageRequiresMember('/info/a/b'));
        self::assertTrue($policy->pageRequiresMember('/infox'));
        self::assertFalse($policy->pageRequiresMember('/vitejte'));
        self::assertFalse($policy->pageRequiresMember('/prihlaseni/link'));
        self::assertTrue($policy->pageRequiresMember('/clanky/novy'));
        self::assertSame('/prihlaseni', $policy->loginPath());
        self::assertTrue($policy->mediaRequiresMember('clanky/foto.jpg'));
    }

    public function testFrontMatterOverridesAndInherits(): void
    {
        $policy = $this->make(['mode' => 'members'], [
            '/'           => 'public',
            '/o-nas'      => 'public',
            '/o-nas/tym'  => 'members',
        ]);
        self::assertFalse($policy->pageRequiresMember('/'));
        self::assertTrue($policy->pageRequiresMember('/clanky'), 'the homepage setting is not inherited');
        self::assertFalse($policy->pageRequiresMember('/o-nas'));
        self::assertFalse($policy->pageRequiresMember('/o-nas/historie'));
        self::assertTrue($policy->pageRequiresMember('/o-nas/tym'));
        self::assertTrue($policy->pageRequiresMember('/o-nas/tym/jan'));

        $public = $this->make([], ['/archiv' => 'members']);
        self::assertTrue($public->pageRequiresMember('/archiv/2020'));
        self::assertFalse($public->pageRequiresMember('/blog'));
    }

    public function testMediaCanBeLeftPublic(): void
    {
        $policy = $this->make(['mode' => 'members', 'media' => false]);
        self::assertFalse($policy->mediaRequiresMember('a/b.jpg'));
        $policy = $this->make(['mode' => 'members', 'public' => ['/media/loga/*']]);
        self::assertFalse($policy->mediaRequiresMember('loga/logo.svg'));
        self::assertTrue($policy->mediaRequiresMember('alba/foto.jpg'));
    }

    public function testMediaFollowTheirPage(): void
    {
        // Public site with one members-only section.
        $policy = $this->make([], ['/blog' => 'members']);
        self::assertTrue($policy->mediaRequiresMember('blog/cover.jpg'));
        self::assertTrue($policy->mediaRequiresMember('blog/post/photo.jpg'), 'inherited from /blog');
        self::assertFalse($policy->mediaRequiresMember('about/team.jpg'));
        self::assertFalse($policy->mediaRequiresMember('~/logo.svg'));
        self::assertFalse($policy->mediaRequiresMember('_collections/banners/sale/a.jpg'));

        // Members site: a public page keeps its media public; collections follow the mode.
        $policy = $this->make(['mode' => 'members', 'public' => ['/']], ['/kontakt' => 'public']);
        self::assertFalse($policy->mediaRequiresMember('~/splash.jpg'), 'the homepage is public');
        self::assertFalse($policy->mediaRequiresMember('kontakt/map.png'));
        self::assertTrue($policy->mediaRequiresMember('alba/foto.jpg'));
        self::assertTrue($policy->mediaRequiresMember('_collections/clenove/jan/a.jpg'));

        $off = $this->make(['media' => false], ['/blog' => 'members']);
        self::assertFalse($off->mediaRequiresMember('blog/cover.jpg'));
    }

    public function testVisiblePagesHidesGatedPagesFromAnonymousVisitors(): void
    {
        $policy = $this->make([], ['/blog' => 'members']);
        $page = function (string $urlPath): Page {
            $p = new Page(slug: basename($urlPath), title: $urlPath, body: '');
            $p->urlPath = $urlPath;
            return $p;
        };
        $pages = [$page('/about'), $page('/blog'), $page('/blog/post'), $page('/contact')];

        self::assertSame(['/about', '/contact'], array_map(fn (Page $p) => $p->urlPath, $policy->visiblePages($pages, false)));
        self::assertCount(4, $policy->visiblePages($pages, true));
    }
}

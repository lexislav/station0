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
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Multilingual;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\Languages;
use Station0\Service\SiteStrings;
use Station0\Service\VisibilityHorizon;

final class HorizonAndStringsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s0-mlh-' . bin2hex(random_bytes(6));
        @mkdir($this->root . '/pages/about', 0775, true);
        @mkdir($this->root . '/lang', 0775, true);
        @mkdir($this->root . '/cache', 0775, true);
        file_put_contents($this->root . '/pages/page.txt', "Title: Home\nStatus: published\n---\n");
        file_put_contents($this->root . '/pages/about/page.txt', "Title: O nás\nStatus: published\n---\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testHorizonSeesScheduleOfATranslation(): void
    {
        $at = time() + 3600;
        file_put_contents($this->root . '/pages/about/page.en.txt', "Title: About\nPublishAt: " . date('Y-m-d H:i', $at) . "\n---\n");
        $languages = new Languages(['cs' => [], 'en' => []]);
        $pages     = new ContentRepository($this->root . '/pages', null, null, $languages);
        $horizon   = new VisibilityHorizon(new FileCache($this->root . '/cache'), $pages, null, $languages);

        $next = $horizon->next(time());
        self::assertNotNull($next);
        self::assertEqualsWithDelta($at, $next, 60);
        self::assertNull((new VisibilityHorizon(new FileCache($this->root . '/cache'), $pages))->next(time()),
            'without Languages only the default files count');
    }

    public function testSiteStrings(): void
    {
        file_put_contents($this->root . '/lang/cs.yaml', "nav:\n  home: Domů\nbye: Nashle {name}\n");
        file_put_contents($this->root . '/lang/en.yaml', "nav:\n  home: Home\n");
        $languages = new Languages(['cs' => [], 'en' => []]);
        $strings   = new SiteStrings($languages, $this->root . '/lang');

        self::assertSame('Domů', $strings->get('nav.home'));
        $languages->setCurrent('en');
        $strings = new SiteStrings($languages, $this->root . '/lang');
        self::assertSame('Home', $strings->get('nav.home'));
        self::assertSame('Nashle Eva', $strings->get('bye', ['name' => 'Eva']), 'falls back to the default language');
        self::assertSame('Fallback', $strings->get('nope', [], 'Fallback'));
        self::assertSame('nope', $strings->get('nope'));
    }

    public function testSiteStringsOnOneLanguageSite(): void
    {
        file_put_contents($this->root . '/lang/default.yaml', "hello: Ahoj\n");
        file_put_contents($this->root . '/lang/broken.yaml', "a: [\n");
        $strings = new SiteStrings(new Languages(), $this->root . '/lang');
        self::assertSame('Ahoj', $strings->get('hello'));
    }
}

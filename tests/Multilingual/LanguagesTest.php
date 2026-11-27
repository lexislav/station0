<?php

declare(strict_types=1);

namespace Station0\Tests\Multilingual;

use PHPUnit\Framework\TestCase;
use Station0\Service\Languages;

final class LanguagesTest extends TestCase
{
    private function cs(): Languages
    {
        return new Languages(['cs' => ['label' => 'Čeština'], 'en' => ['label' => 'English', 'locale' => 'en_GB'], 'de' => []]);
    }

    public function testNotMultilingualWithoutOrWithOneLanguage(): void
    {
        foreach ([new Languages(), new Languages(['cs' => []])] as $l) {
            self::assertFalse($l->enabled());
            self::assertSame('', $l->current());
            self::assertSame('', $l->default());
            self::assertSame([], $l->codes());
            self::assertSame(['', '/en/about'], [$l->prefix('en'), $l->split('/en/about')[1]]);
            self::assertSame('/about', $l->url('/about', 'en'));
            self::assertFalse($l->isTranslationFile('/x/page.en.txt'));
        }
    }

    public function testDefaultIsFirstAndUnprefixed(): void
    {
        $l = $this->cs();
        self::assertTrue($l->enabled());
        self::assertSame('cs', $l->default());
        self::assertSame('cs', $l->current());
        self::assertSame(['cs', 'en', 'de'], $l->codes());
        self::assertSame('', $l->prefix('cs'));
        self::assertSame('/en', $l->prefix('en'));
        self::assertSame('/about', $l->url('/about', 'cs'));
        self::assertSame('/en/about', $l->url('about', 'en'));
        self::assertSame('/en', $l->url('/', 'en'));
        self::assertSame('en_GB', $l->locale('en'));
        self::assertSame('DE', $l->label('de'));
    }

    public function testSplit(): void
    {
        $l = $this->cs();
        self::assertSame(['en', '/about/team'], $l->split('/en/about/team'));
        self::assertSame(['en', '/'], $l->split('/en'));
        self::assertSame(['en', '/'], $l->split('/en/'));
        self::assertSame(['cs', '/cs/about'], $l->split('/cs/about'), 'the default language has no prefix');
        self::assertSame(['cs', '/english'], $l->split('/english'));
        self::assertSame(['cs', '/fr/x'], $l->split('/fr/x'), 'unknown code = a normal path');
    }

    public function testCurrentFollowsSetCurrentAndIgnoresUnknown(): void
    {
        $l = $this->cs();
        $l->setCurrent('en');
        self::assertSame('en', $l->current());
        self::assertSame('/en/x', $l->url('/x'));
        $l->setCurrent('fr');
        self::assertSame('en', $l->current());
    }

    public function testTranslationFiles(): void
    {
        $l = $this->cs();
        self::assertSame('/p/about/page.en.txt', Languages::translationFile('/p/about/page.txt', 'en'));
        self::assertTrue($l->isTranslationFile('/p/about/page.en.txt'));
        self::assertTrue($l->isTranslationFile('/p/about/article.de.txt'));
        self::assertFalse($l->isTranslationFile('/p/about/page.cs.txt'), 'default language has no translation file');
        self::assertFalse($l->isTranslationFile('/p/about/page.fr.txt'));
        self::assertFalse($l->isTranslationFile('/p/about/page.txt'));
    }

    public function testInvalidCodesAndFallbackMode(): void
    {
        $l = new Languages(['cs', 'EN', 'not a code', '../x'], 'content');
        self::assertSame(['cs', 'en'], $l->codes());
        self::assertSame(Languages::FALLBACK_CONTENT, $l->fallback());
        self::assertSame(Languages::FALLBACK_404, (new Languages(['cs', 'en'], 'weird'))->fallback());
    }
}

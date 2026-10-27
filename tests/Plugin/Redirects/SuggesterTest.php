<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PHPUnit\Framework\TestCase;
use Station0\Plugin\Redirects\Suggester;

final class SuggesterTest extends TestCase
{
    private function suggester(): Suggester
    {
        return new Suggester(['/', '/blog', '/blog/hello-world', '/news/hello-world-2', '/about', '/shop/chairs']);
    }

    public function testSameSlugWins(): void
    {
        self::assertSame(['to' => '/blog/hello-world', 'reason' => 'slug'], $this->suggester()->suggest('/old/hello-world'));
        self::assertSame(['to' => '/about', 'reason' => 'slug'], $this->suggester()->suggest('/company/About.html'));
    }

    public function testTyposThenParent(): void
    {
        self::assertSame(['to' => '/about', 'reason' => 'similar'], $this->suggester()->suggest('/abuot'));
        self::assertSame(['to' => '/blog', 'reason' => 'parent'], $this->suggester()->suggest('/blog/2019/some-removed-post'));
    }

    public function testNeverTheHomepageOrALivePage(): void
    {
        self::assertNull($this->suggester()->suggest('/completely-unrelated-thing'));
        self::assertNull($this->suggester()->suggest('/about'));
        self::assertNull($this->suggester()->suggest('/'));
    }
}

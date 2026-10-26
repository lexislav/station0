<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PHPUnit\Framework\TestCase;
use Station0\Plugin\Redirects\Compiler;
use Station0\Plugin\Redirects\Matcher;
use Station0\Plugin\Redirects\Rules;

final class MatcherTest extends TestCase
{
    private function matcher(array $raw): Matcher
    {
        return new Matcher(Compiler::compile(array_map([Rules::class, 'normalize'], $raw)));
    }

    private function go(Matcher $m, string $path, string $query = '', bool $force = false): ?string
    {
        $hit = $m->match($path, $query, $force);
        return $hit === null ? null : $hit['status'] . ' ' . ($hit['location'] ?? '-');
    }

    public function testAllRuleTypes(): void
    {
        $m = $this->matcher([
            ['from' => '/old-page', 'to' => '/new-page'],
            ['from' => '/blog/{year}/{slug}', 'to' => '/articles/{slug}'],
            ['from' => '/docs/{path*}', 'to' => 'https://docs.example.com/{path}'],
            ['from' => '^/p/(\d+)$', 'type' => 'regex', 'to' => '/products/$1', 'status' => 302],
            ['from' => '/discontinued', 'status' => 410],
        ]);

        self::assertSame('301 /new-page', $this->go($m, '/old-page'));
        self::assertSame('301 /new-page', $this->go($m, '/old-page/'));            // trailing slash
        self::assertNull($this->go($m, '/Old-Page'));                               // case-sensitive
        self::assertSame('301 /articles/hello?a=1', $this->go($m, '/blog/2019/hello', 'a=1'));
        self::assertNull($this->go($m, '/blog/2019'));
        self::assertSame('301 https://docs.example.com/', $this->go($m, '/docs'));
        self::assertSame('301 https://docs.example.com/a/b', $this->go($m, '/docs/a/b'));
        self::assertSame('302 /products/12', $this->go($m, '/p/12'));
        self::assertNull($this->go($m, '/p/x'));
        self::assertSame('410 -', $this->go($m, '/discontinued'));
        self::assertNull($this->go($m, '/nothing'));
    }

    public function testExactBeforePatternAndFileOrderWithinEach(): void
    {
        $m = $this->matcher([
            ['from' => '/a/{x}', 'to' => '/pattern-first'],
            ['from' => '/a/{y}', 'to' => '/pattern-second'],
            ['from' => '/a/b', 'to' => '/exact'],
            ['from' => '/C', 'to' => '/caseless', 'caseless' => true],
            ['from' => '/c', 'to' => '/later-exact'],
        ]);
        self::assertSame('301 /exact', $this->go($m, '/a/b'));
        self::assertSame('301 /pattern-first', $this->go($m, '/a/z'));
        self::assertSame('301 /caseless', $this->go($m, '/c'));       // the earlier rule wins
        self::assertSame('301 /caseless', $this->go($m, '/C'));
    }

    public function testCaselessPatternsAndRegexes(): void
    {
        $m = $this->matcher([
            ['from' => '/Shop/{item}', 'to' => '/store/{item}', 'caseless' => true],
            ['from' => '^/x/(\d+)$', 'type' => 'regex', 'to' => '/y/$1'],
        ]);
        self::assertSame('301 /store/Bag', $this->go($m, '/SHOP/Bag'));
        self::assertNull($this->go($m, '/X/1'));
    }

    public function testManyRulesCompileIntoOneRegexWithMarks(): void
    {
        $raw = [];
        for ($i = 0; $i < 300; $i++) {
            $raw[] = ['from' => "/section{$i}/{slug}", 'to' => "/s{$i}/{slug}"];
        }
        $raw[] = ['from' => '^/legacy/(\d+)/(\w+)\.html$', 'type' => 'regex', 'to' => '/new/$2/$1'];
        $set = Compiler::compile(array_map([Rules::class, 'normalize'], $raw));

        self::assertSame(1, substr_count($set['regex'], '^(?|'));
        self::assertStringContainsString('(*MARK:300)', $set['regex']);
        $m = new Matcher($set);
        self::assertSame('301 /s0/x', $this->go($m, '/section0/x'));
        self::assertSame('301 /s299/y', $this->go($m, '/section299/y'));
        self::assertSame('301 /new/post/7', $this->go($m, '/legacy/7/post.html'));
    }

    public function testForceRulesAreSeparateFromFallbacks(): void
    {
        $m = $this->matcher([
            ['from' => '/about-us', 'to' => '/about', 'force' => true],
            ['from' => '/team/{x}', 'to' => '/people/{x}', 'force' => true],
            ['from' => '/old', 'to' => '/new'],
        ]);
        self::assertTrue($m->hasForce());
        self::assertSame('301 /about', $this->go($m, '/about-us', '', true));
        self::assertSame('301 /people/a', $this->go($m, '/team/a', '', true));
        self::assertNull($this->go($m, '/about-us'));            // not a fallback
        self::assertNull($this->go($m, '/old', '', true));       // not forced
        self::assertSame('301 /about', $m->test('/about-us')['status'] . ' ' . $m->test('/about-us')['location']);
        self::assertFalse($this->matcher([['from' => '/a', 'to' => '/b']])->hasForce());
    }

    public function testAdminIndexes(): void
    {
        $m = $this->matcher([
            ['from' => '/old-about', 'to' => '/about'],
            ['from' => '/legacy/{x}', 'to' => '/about?from={x}'],   // captured target: not attributed to /about
            ['from' => '/team/{path*}', 'to' => '/about/team/{path}', 'auto' => '2026-10-26'],
            ['from' => '/about/team/lead', 'to' => '/elsewhere', 'force' => true],
            ['from' => '/about', 'to' => '/shadowed'],
        ]);

        $about = $m->incoming('/about');
        self::assertCount(1, $about);
        self::assertSame('/old-about', $about[0]['rule']['from']);

        $lead = $m->incoming('/about/team/lead');
        self::assertCount(1, $lead);
        self::assertSame('/team/lead', $lead[0]['via']);
        self::assertSame('/team', $m->incoming('/about/team')[0]['via']);

        self::assertSame('/about/team/lead', $m->blocking('/about/team/lead')[0]['from']);
        self::assertSame([], $m->blocking('/about'));
        self::assertSame('/shadowed', $m->shadowed('/about')[0]['to']);
    }
}

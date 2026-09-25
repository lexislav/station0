<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use PHPUnit\Framework\TestCase;
use Station0\Support\FrontMatter;

final class FrontMatterTest extends TestCase
{
    public function testSingleLineValuesAreReadVerbatim(): void
    {
        [$meta, $body] = FrontMatter::parse("Title: Hello: world \"x\"\nPublished: true\nOdd: | pipe\n---\n\nBody text\n");

        self::assertSame(['title' => 'Hello: world "x"', 'published' => 'true', 'odd' => '| pipe'], $meta);
        self::assertSame("Body text\n", $body);
    }

    public function testNoSeparatorMeansBodyOnly(): void
    {
        self::assertSame([[], "just text"], FrontMatter::parse("just text"));
    }

    public function testMultiLineStringRoundTrips(): void
    {
        $value = "  indented first\nline two\n\nafter blank\n";
        [$meta] = FrontMatter::parse(FrontMatter::serialize(['Summary' => $value], ''));

        self::assertSame($value, $meta['summary']);
    }

    public function testCrLfIsNormalized(): void
    {
        [$meta] = FrontMatter::parse(FrontMatter::serialize(['Summary' => "a\r\nb"], ''));

        self::assertSame("a\nb", $meta['summary']);
    }

    public function testListOfMapsRoundTrips(): void
    {
        $value = [
            ['title' => 'Fast', 'text' => "multi\nline"],
            ['title' => 'B: c', 'text' => ''],
        ];
        $raw = FrontMatter::serialize(['Title' => 'T', 'Features' => $value, 'Flag' => true], "- type: text\n  body: hi");
        [$meta, $body] = FrontMatter::parse($raw);

        self::assertSame($value, $meta['features']);
        self::assertSame('true', $meta['flag']);
        self::assertSame('T', $meta['title']);
        self::assertSame("- type: text\n  body: hi\n", $body);
        self::assertStringContainsString("Features:\n  - title: Fast\n", $raw);
    }

    public function testEmptyValuesAreOmitted(): void
    {
        self::assertSame("A: x\n---\n\n\n", FrontMatter::serialize(['A' => 'x', 'B' => '', 'C' => null, 'D' => []], ''));
    }

    public function testKeyFollowingABlockIsStillParsed(): void
    {
        [$meta] = FrontMatter::parse("Text: |\n  one\n  two\nAfter: yes\n---\n");

        self::assertSame("one\ntwo\n", $meta['text']);
        self::assertSame('yes', $meta['after']);
    }

    public function testBrokenBlockKeepsRawText(): void
    {
        [$meta] = FrontMatter::parse("Bad:\n  - [unclosed\n---\n");

        self::assertSame('- [unclosed', $meta['bad']);
    }
}

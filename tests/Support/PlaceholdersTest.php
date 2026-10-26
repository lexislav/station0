<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use PHPUnit\Framework\TestCase;
use Station0\Support\Placeholders;

final class PlaceholdersTest extends TestCase
{
    public function testFillsKeysCaseInsensitively(): void
    {
        self::assertSame('Jan Novák', Placeholders::fill('{Name} {SURNAME}', ['name' => 'Jan', 'Surname' => 'Novák']));
    }

    public function testLiteralTextBetweenFilledPlaceholdersIsKept(): void
    {
        self::assertSame('Škoda – Octavia (2024)', Placeholders::fill('{brand} – {model} ({year})', [
            'brand' => 'Škoda', 'model' => 'Octavia', 'year' => 2024,
        ]));
        // No cleanup when nothing is empty, even around odd literals.
        self::assertSame('A -- B', Placeholders::fill('{a} -- {b}', ['a' => 'A', 'b' => 'B']));
    }

    public function testWhitespaceIsCollapsedAndTrimmed(): void
    {
        self::assertSame('Jan Novák', Placeholders::fill('  {name}   {surname} ', ['name' => " Jan\n", 'surname' => 'Novák']));
    }

    public function testMissingAndNonScalarValuesCountAsEmpty(): void
    {
        self::assertSame('Jan', Placeholders::fill('{name} {surname}', ['name' => 'Jan']));
        self::assertSame('Jan', Placeholders::fill('{name} {tags}', ['name' => 'Jan', 'tags' => ['a']]));
        self::assertSame('', Placeholders::fill('{name} - {surname}', []));
        self::assertSame('true', Placeholders::fill('{flag}', ['flag' => true]));
    }

    /** @dataProvider separatorCases */
    public function testSeparatorsLeftByEmptyPlaceholdersAreCleanedUp(string $template, array $values, string $expected): void
    {
        self::assertSame($expected, Placeholders::fill($template, $values));
    }

    public static function separatorCases(): array
    {
        return [
            'trailing dash'    => ['{brand} - {model}', ['brand' => 'Škoda'], 'Škoda'],
            'leading dash'     => ['{brand} - {model}', ['model' => 'Octavia'], 'Octavia'],
            'middle doubled'   => ['{a} - {b} - {c}', ['a' => 'A', 'c' => 'C'], 'A - C'],
            'comma list'       => ['{a}, {b}, {c}', ['a' => 'A', 'c' => 'C'], 'A, C'],
            'en dash'          => ['{a} – {b}', ['a' => 'A'], 'A'],
            'slash no spaces'  => ['{a}/{b}', ['b' => 'B'], 'B'],
            'empty brackets'   => ['{brand} ({year})', ['brand' => 'Škoda'], 'Škoda'],
            'empty square'     => ['{brand} [{code}]', ['brand' => 'Škoda'], 'Škoda'],
            'pipe'             => ['{a} | {b}', ['a' => 'A'], 'A'],
            'hyphen in value'  => ['{name} - {city}', ['name' => 'Jean-Luc'], 'Jean-Luc'],
        ];
    }

    public function testNamesListsPlaceholdersLowerCasedAndUnique(): void
    {
        self::assertSame(['name', 'surname'], Placeholders::names('{Name} {surname} ({NAME})'));
        self::assertSame([], Placeholders::names('plain'));
    }
}

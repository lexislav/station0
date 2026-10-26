<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use PHPUnit\Framework\TestCase;
use Station0\Support\VersionConstraint;

final class VersionConstraintTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['0.10.0', '^0.10', true],
            ['0.10.5', '^0.10', true],
            ['0.11.0', '^0.10', false],
            ['0.9.3', '^0.10', false],
            ['1.4.0', '^1.2', true],
            ['2.0.0', '^1.2', false],
            ['0.0.3', '^0.0.3', true],
            ['0.0.4', '^0.0.3', false],
            ['1.9.0', '~1.2', true],
            ['2.0.0', '~1.2', false],
            ['1.2.9', '~1.2.3', true],
            ['1.3.0', '~1.2.3', false],
            ['0.10.2', '>=0.10 <0.12', true],
            ['0.12.0', '>=0.10, <0.12', false],
            ['0.10.7', '0.10.*', true],
            ['0.11.0', '0.10.*', false],
            ['0.9.0', '^0.9 || ^0.10', true],
            ['0.10.1', '^0.9 || ^0.10', true],
            ['v0.10.1', '^0.10', true],
            ['1.2.3', '1.2.3', true],
            ['1.2.4', '1.2.3', false],
            ['1.0.0', 'nonsense', false],
        ];
    }

    /** @dataProvider cases */
    public function testSatisfies(string $version, string $constraint, bool $expected): void
    {
        self::assertSame($expected, VersionConstraint::satisfies($version, $constraint), "{$version} vs {$constraint}");
    }

    public function testIsRelease(): void
    {
        self::assertTrue(VersionConstraint::isRelease('0.10.0'));
        self::assertTrue(VersionConstraint::isRelease('v1.2'));
        self::assertFalse(VersionConstraint::isRelease('dev-main'));
        self::assertFalse(VersionConstraint::isRelease('1.0.0-beta1'));
    }
}

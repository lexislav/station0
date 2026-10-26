<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Station0\Plugin\Redirects\Compiler;
use Station0\Plugin\Redirects\Matcher;
use Station0\Plugin\Redirects\Rules;

/**
 * Budget from the design: 10 000 exact + 500 pattern rules, a path no rule
 * matches resolves in < 0.2 ms. Not part of normal runs:
 *   vendor/bin/phpunit --group bench
 */
#[Group('bench')]
final class MatcherBenchTest extends TestCase
{
    public function testLargeRuleSetStaysWithinBudget(): void
    {
        $raw = [];
        for ($i = 0; $i < 10000; $i++) {
            $raw[] = ['from' => "/old/page-{$i}", 'to' => "/new/page-{$i}"];
        }
        for ($i = 0; $i < 500; $i++) {
            $raw[] = ['from' => "/archive{$i}/{year}/{slug}", 'to' => "/posts{$i}/{slug}"];
        }
        $file = sys_get_temp_dir() . '/s0-bench-' . bin2hex(random_bytes(4)) . '.php';
        Compiler::write(Compiler::compile(array_map([Rules::class, 'normalize'], $raw)), $file);

        $t = hrtime(true);
        $matcher = new Matcher(require $file);
        $load = (hrtime(true) - $t) / 1e6;
        unlink($file);

        $runs = 2000;
        $t = hrtime(true);
        for ($i = 0; $i < $runs; $i++) {
            $miss = $matcher->match('/no/such/page-' . $i, 'a=1');
        }
        $perMiss = (hrtime(true) - $t) / 1e6 / $runs;

        self::assertNull($miss);
        self::assertSame('/posts499/x', $matcher->match('/archive499/2020/x')['location']);
        fwrite(STDERR, sprintf("\n  load (no opcache): %.2f ms, non-matching lookup: %.4f ms\n", $load, $perMiss));
        self::assertLessThan(0.2, $perMiss, 'non-matching lookup over budget');
    }
}

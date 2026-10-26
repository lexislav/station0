<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Station0\Tests\Plugin\Fixtures\FixturePlugin;

/** bin/console runs plugin commands (a real process against a TestSite). */
final class PluginConsoleTest extends TestCase
{
    private TestSite $site;

    protected function setUp(): void
    {
        $this->site = new TestSite([FixturePlugin::class => ['greeting' => 'ahoj']]);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    /** @return array{int, string} exit code, stdout */
    private function console(string ...$args): array
    {
        $root = dirname(__DIR__, 2);
        $cmd  = 'SITE_PATH=' . escapeshellarg($this->site->dir . '/site') . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/bin/console') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $out = [];
        exec('cd ' . escapeshellarg($root) . ' && ' . $cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function testPluginCommandRuns(): void
    {
        [$code, $out] = $this->console('fixture:hello', 'a', 'b');
        self::assertSame(0, $code, $out);
        self::assertSame('ahoj a b ArrayObject', $out);

        [$code] = $this->console('fixture:hello', '--fail');
        self::assertSame(3, $code);
    }

    public function testHelpAndPluginsListShowThePlugin(): void
    {
        [, $help] = $this->console('help');
        self::assertStringContainsString('fixture:hello', $help);
        self::assertStringContainsString('Says hello [fixture]', $help);

        [$code, $list] = $this->console('plugins:list');
        self::assertSame(0, $code, $list);
        self::assertStringContainsString('fixture', $list);
        self::assertStringContainsString(FixturePlugin::class, $list);
        self::assertStringContainsString('migrace 001-things', $list);
        self::assertStringNotContainsString('neaplikována', $list);
    }
}

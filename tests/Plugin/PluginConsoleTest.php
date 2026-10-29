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

    public function testSanitizeSvgCleansStoredFiles(): void
    {
        $clean = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>';
        $this->site->write('site/content/pages/about/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><script>x()</script><circle r="1"/></svg>');
        $this->site->write('site/content/pages/about/ok.svg', $clean);
        $this->site->write('site/content/collections/products/chair/bad.svg', '<html>not svg</html>');
        $evil = $this->site->dir . '/site/content/pages/about/evil.svg';

        [$code, $out] = $this->console('assets:sanitize-svg', '--dry-run');
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[dry] pages/about/evil.svg: @onload, <script>', $out);
        self::assertStringContainsString('collections/products/chair/bad.svg: NEPLATNÉ', $out);
        self::assertStringNotContainsString('ok.svg', $out);
        self::assertStringContainsString('<script>', (string) file_get_contents($evil));

        [, $out] = $this->console('assets:sanitize-svg');
        self::assertStringContainsString('vyčištěno 1 SVG, neplatných 1', $out);
        self::assertStringNotContainsString('script', (string) file_get_contents($evil));
        self::assertSame($clean, file_get_contents($this->site->dir . '/site/content/pages/about/ok.svg'));
        self::assertSame('<html>not svg</html>', file_get_contents($this->site->dir . '/site/content/collections/products/chair/bad.svg'));
    }
}

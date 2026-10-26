<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Station0\Plugin\Migrations;
use Station0\Plugin\Plugins;
use Station0\Plugin\Registrar;
use Station0\Service\CollectionItem;
use Station0\Service\Page;
use Station0\Tests\Plugin\Fixtures\BrokenRegisterPlugin;
use Station0\Tests\Plugin\Fixtures\FixturePlugin;
use Station0\Tests\Plugin\Fixtures\ThrowingPlugin;

final class PluginsTest extends TestCase
{
    private const BUNDLED = 'Station0\\Tests\\Plugin\\Fixtures\\Bundled\\';

    private TestHandler $log;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->log    = new TestHandler();
        $this->logger = new Logger('test', [$this->log]);
    }

    public function testResolvesShortNamesToBundledClassesAndKeepsFqcn(): void
    {
        self::assertSame('Station0\\Plugin\\Redirects\\Plugin', Plugins::resolveClass('redirects'));
        self::assertSame('Station0\\Plugin\\SeoMeta\\Plugin', Plugins::resolveClass('seo-meta'));
        self::assertSame('Acme\\Sitemap\\Plugin', Plugins::resolveClass('\\Acme\\Sitemap\\Plugin'));
    }

    public function testLoadsBundledAndFqcnPluginsInConfigOrder(): void
    {
        $plugins = Plugins::load([
            FixturePlugin::class => ['greeting' => 'ahoj'],
            'hello'              => [],
        ], '/admin', $this->logger, self::BUNDLED);

        self::assertSame(['fixture', 'hello'], $plugins->names());
        self::assertSame('ahoj', $plugins->get('fixture')->config('greeting'));
        self::assertTrue($plugins->get('fixture')->config('middleware')); // default kept
        self::assertNotNull($plugins->command('hello:world'));
        self::assertFalse($this->log->hasErrorRecords());
    }

    public function testListFormTrueAndFalse(): void
    {
        $plugins = Plugins::load(['hello', FixturePlugin::class => false], '/admin', $this->logger, self::BUNDLED);
        self::assertSame(['hello'], $plugins->names());

        $plugins = Plugins::load(['hello' => true], '/admin', $this->logger, self::BUNDLED);
        self::assertSame(['hello'], $plugins->names());
    }

    public function testUnlistedPluginsAreNeverAutoloaded(): void
    {
        $class = 'Station0\\Tests\\Plugin\\Fixtures\\NeverLoadedPlugin';
        Plugins::load([FixturePlugin::class => []], '/admin', $this->logger);
        self::assertFalse(class_exists($class, false), 'an unlisted plugin class was loaded');
        self::assertTrue(Plugins::none()->isEmpty());
    }

    public function testBrokenPluginsAreLoggedAndSkipped(): void
    {
        $plugins = Plugins::load([
            'missing'                     => [],                       // no bundled class
            'Acme\\Nope\\Plugin'          => [],                       // no such class
            \stdClass::class              => [],                       // not a Plugin
            ThrowingPlugin::class         => [],                       // constructor throws
            BrokenRegisterPlugin::class   => [],                       // register() throws
            FixturePlugin::class          => ['greeting' => 42],       // configure() refuses
            'hello'                       => 'yes',                    // options not an array
        ], '/admin', $this->logger, self::BUNDLED);

        self::assertTrue($plugins->isEmpty());
        self::assertCount(7, array_filter($this->log->getRecords(), fn ($r) => str_contains($r['message'], 'skipped')));
        self::assertTrue($this->log->hasErrorThatContains("Plugin 'missing' skipped: class Station0\\Tests\\Plugin\\Fixtures\\Bundled\\Missing\\Plugin not found"));
        self::assertTrue($this->log->hasErrorThatContains('boom in constructor'));
        self::assertTrue($this->log->hasErrorThatContains("Invalid content target 'pages'"));
    }

    public function testBrokenPluginDoesNotStopTheOthers(): void
    {
        $plugins = Plugins::load([ThrowingPlugin::class => [], 'hello' => []], '/admin', $this->logger, self::BUNDLED);
        self::assertSame(['hello'], $plugins->names());
    }

    public function testDuplicateNameIsSkipped(): void
    {
        $plugins = Plugins::load(['hello' => [], 'Station0\\Tests\\Plugin\\Fixtures\\Bundled\\Hello\\Plugin' => []], '/admin', $this->logger, self::BUNDLED);
        self::assertSame(['hello'], $plugins->names());
        self::assertTrue($this->log->hasErrorThatContains("a plugin named 'hello' is already enabled"));
    }

    public function testReservedNameIsSkipped(): void
    {
        $plugin = new class () extends \Station0\Plugin\BasePlugin {
            public function name(): string { return 'pages'; }
            public function register(Registrar $r): void {}
        };
        $plugins = Plugins::load([get_class($plugin) => []], '/admin', $this->logger);
        self::assertTrue($plugins->isEmpty());
        self::assertTrue($this->log->hasErrorThatContains("invalid or reserved plugin name 'pages'"));
    }

    public function testDeclarationsAreCollectedPerKind(): void
    {
        $plugins = Plugins::load([FixturePlugin::class => []], '/admin', $this->logger);

        self::assertCount(2, $plugins->all('adminRoutes'));
        self::assertSame(['fixture.001-things'], array_keys($plugins->migrations()));
        self::assertSame('Fixture tab', $plugins->translations('en')['fixture_nav_label']);
        self::assertSame('Záložka fixture', $plugins->translations('cs')['fixture_nav_label']);
        self::assertSame('Fixture panel', $plugins->translations('cs')['fixture_panel']); // en fallback
        self::assertNull($plugins->command('nope'));
        self::assertSame('fixture', $plugins->command('fixture:hello')['plugin']);
    }

    public function testRegistrarGetNeedsTheContainer(): void
    {
        $r = new Registrar(new FixturePlugin());
        self::assertSame('/admin/fixture/x', $r->adminUrl('/x'));
        $this->expectException(\LogicException::class);
        $r->get('anything');
    }

    public function testContentTargets(): void
    {
        $post = new Page(slug: 'post', title: 'Post', body: '', template: 'article');
        $post->urlPath = '/blog/post';
        $item = new CollectionItem(collection: 'products', slug: 'chair', title: 'Chair', body: '');

        self::assertTrue(Plugins::targets('page', 'page', $post));
        self::assertTrue(Plugins::targets('page', 'page', null));
        self::assertTrue(Plugins::targets('page:/blog', 'page', $post));
        self::assertTrue(Plugins::targets('page:blog/', 'page', $post));
        self::assertFalse(Plugins::targets('page:/bl', 'page', $post));
        self::assertFalse(Plugins::targets('page:/blog', 'page', null));
        self::assertTrue(Plugins::targets('page@article', 'page', $post));
        self::assertTrue(Plugins::targets('page:/blog@article', 'page', $post));
        self::assertFalse(Plugins::targets('page@product', 'page', $post));
        self::assertFalse(Plugins::targets('page', 'collectionItem', $item));
        self::assertTrue(Plugins::targets('collectionItem', 'collectionItem', $item));
        self::assertTrue(Plugins::targets('collectionItem:prod*', 'collectionItem', $item));
        self::assertFalse(Plugins::targets('collectionItem:banners', 'collectionItem', $item));
    }

    public function testMigrationsApplyOnce(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $migrations = [
            'a.001' => 'CREATE TABLE a_things (id INTEGER PRIMARY KEY)',
            'a.002' => 'CREATE TABLE broken (',
        ];

        self::assertSame(['a.001'], Migrations::apply($pdo, $migrations, $this->logger));
        self::assertTrue($this->log->hasErrorThatContains("Migration 'a.002' failed"));
        self::assertSame([], Migrations::apply($pdo, ['a.001' => $migrations['a.001']], $this->logger));
        $pdo->exec('INSERT INTO a_things (id) VALUES (1)');
        self::assertSame(['a.001'], $pdo->query('SELECT id FROM ' . Migrations::TABLE)->fetchAll(\PDO::FETCH_COLUMN));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use Delight\Auth\Role;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Station0\Plugin\Plugins;
use Station0\Support\Events;
use Station0\Tests\Plugin\Fixtures\FixturePlugin;
use Station0\Tests\Plugin\Fixtures\ThrowingPlugin;

/**
 * The full app with the fixture plugin enabled (see BootsApp).
 */
final class PluginAppTest extends TestCase
{
    use BootsApp;

    /** @param ?int $role Role::ADMIN / Role::EDITOR, null = signed out */
    private function app(array $plugins, ?int $role = Role::ADMIN): App
    {
        // The page template uses the fixture plugin's Twig function, filter and global.
        return $this->boot($plugins, $role, $plugins === [] || isset($plugins[ThrowingPlugin::class])
            ? null
            : '<h1>{{ page.title }}</h1>{{ content|raw }}|{{ fixture_hello() }}|{{ "x"|shout }}|{{ fixture.version ?? "" }}');
    }

    public function testPublicRouteTwigAndMiddleware(): void
    {
        $app = $this->app([FixturePlugin::class => ['greeting' => 'ahoj']]);

        $hello = $this->get($app, '/fixture-hello');
        self::assertSame(200, $hello->getStatusCode());
        self::assertSame('ahoj from fixture', (string) $hello->getBody());
        self::assertSame('on', $hello->getHeaderLine('X-Fixture'));

        // Twig function, filter and global in a site template.
        $page = (string) $this->get($app, '/about')->getBody();
        self::assertStringContainsString('|hi from twig|X|7', $page);
    }

    public function testMiddlewareIsLeftOutWhenWhenSaysNo(): void
    {
        $app = $this->app([FixturePlugin::class => ['middleware' => false]]);
        self::assertSame('', $this->get($app, '/fixture-hello')->getHeaderLine('X-Fixture'));
    }

    public function testAdminRoutesAreSignedInAndRoleGuarded(): void
    {
        $app = $this->app([FixturePlugin::class => []], null);
        $res = $this->get($app, '/admin/fixture');
        self::assertSame(302, $res->getStatusCode());
        self::assertStringStartsWith('/admin/', $res->getHeaderLine('Location'));

        $app = $this->app([FixturePlugin::class => []], Role::EDITOR);
        self::assertSame(403, $this->get($app, '/admin/fixture')->getStatusCode());
        self::assertSame('fixture notes', (string) $this->get($app, '/admin/fixture/notes')->getBody());

        $app = $this->app([FixturePlugin::class => []], Role::ADMIN);
        self::assertSame('fixture admin', (string) $this->get($app, '/admin/fixture')->getBody());
        // admin passes an editor-role route too
        self::assertSame(200, $this->get($app, '/admin/fixture/notes')->getStatusCode());
    }

    public function testNotFoundResolverShortCircuitsThe404(): void
    {
        $app = $this->app([FixturePlugin::class => []]);

        $old = $this->get($app, '/old');
        self::assertSame(301, $old->getStatusCode());
        self::assertSame('/new', $old->getHeaderLine('Location'));

        $media = $this->get($app, '/media/old/a.jpg');
        self::assertSame(301, $media->getStatusCode());
        self::assertSame('/media/new/a.jpg', $media->getHeaderLine('Location'));

        $missing = $this->get($app, '/nowhere');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('not found /nowhere 404', (string) $missing->getBody());
    }

    public function testNavItemRenderedForTheRole(): void
    {
        $app  = $this->app([FixturePlugin::class => []], Role::EDITOR);
        $html = (string) $this->get($app, '/admin/pages')->getBody();
        self::assertStringContainsString('data-nav="plugin-fixture" href="/admin/fixture"', $html);
        self::assertStringContainsString('Fixture tab', $html);
    }

    public function testBadgesInStructureTreeAndItemList(): void
    {
        $app  = $this->app([FixturePlugin::class => []]);
        $tree = (string) $this->get($app, '/admin/pages')->getBody();
        self::assertStringContainsString('<span class="badge-plugin tone-warn" data-plugin="fixture">FX-about</span>', $tree);
        self::assertStringContainsString('FX-post', $tree);
        self::assertStringNotContainsString('FX-</span>', $tree); // root page: provider returned null

        $items = (string) $this->get($app, '/admin/collections/products')->getBody();
        self::assertStringContainsString('FXI-chair', $items);
    }

    public function testContentPanelsOnPageEditForms(): void
    {
        $app = $this->app([FixturePlugin::class => []]);

        $post = (string) $this->get($app, '/admin/pages/blog/post/edit')->getBody();
        self::assertStringContainsString('id="panel-fixture-info"', $post);
        self::assertStringContainsString('<summary>Fixture panel</summary>', $post);
        self::assertStringContainsString('/blog/post ed@example.com @ /admin/fixture', $post);
        self::assertStringContainsString('blog:/blog/post', $post);
        self::assertStringContainsString('admins:/blog/post', $post);

        $about = (string) $this->get($app, '/admin/pages/about/edit')->getBody();
        self::assertStringNotContainsString('blog:', $about); // page:/blog target only

        $quiet = (string) $this->get($app, '/admin/pages/quiet/edit')->getBody();
        self::assertStringNotContainsString('id="panel-fixture-info"', $quiet); // data() returned null

        $new = (string) $this->get($app, '/admin/pages/new')->getBody();
        self::assertStringContainsString('available after the first save', $new);
        self::assertStringNotContainsString('id="panel-fixture-blog-only"', $new);
    }

    public function testRolePanelsHiddenFromOtherRoles(): void
    {
        $app  = $this->app([FixturePlugin::class => []], Role::EDITOR);
        $post = (string) $this->get($app, '/admin/pages/blog/post/edit')->getBody();
        self::assertStringContainsString('id="panel-fixture-info"', $post);
        self::assertStringNotContainsString('admins:', $post);
    }

    public function testContentPanelsOnCollectionItemForms(): void
    {
        $app  = $this->app([FixturePlugin::class => []]);
        $edit = (string) $this->get($app, '/admin/collections/products/chair/edit')->getBody();
        self::assertStringContainsString('item:products/chair', $edit);

        $new = (string) $this->get($app, '/admin/collections/products/new')->getBody();
        self::assertStringNotContainsString('id="panel-fixture-item"', $new);
    }

    public function testEventListenersRunBesideTaskHooks(): void
    {
        $app       = $this->app([FixturePlugin::class => []]);
        $container = $app->getContainer();

        $container->get(Events::class)->dispatch('page.saved', ['path' => '/blog/post']);
        $container->get(Events::class)->dispatch('page.saved', ['path' => '/about']);

        self::assertSame(['page.saved /blog/post'], $container->get('fixture.calls')->getArrayCopy());
    }

    public function testMigrationAppliedWhenTheDatabaseOpens(): void
    {
        $app = $this->app([FixturePlugin::class => []]);
        $pdo = $app->getContainer()->get(\PDO::class);
        $pdo->exec("INSERT INTO fixture_things (name) VALUES ('x')");
        self::assertSame(['fixture.001-things'], $pdo->query('SELECT id FROM station0_migrations')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testBrokenPluginIsLoggedAndTheAppStillBoots(): void
    {
        $app = $this->app([ThrowingPlugin::class => [], FixturePlugin::class => []]);
        self::assertSame(200, $this->get($app, '/about')->getStatusCode());
        self::assertSame(['fixture'], $app->getContainer()->get(Plugins::class)->names());
        self::assertTrue($this->log->hasErrorThatContains('boom in constructor'));
    }

    public function testNoPluginsLeavesTheAppAsBefore(): void
    {
        $app = $this->app([]);
        self::assertTrue($app->getContainer()->get(Plugins::class)->isEmpty());
        self::assertSame(404, $this->get($app, '/fixture-hello')->getStatusCode());
        self::assertSame(404, $this->get($app, '/old')->getStatusCode());
        $html = (string) $this->get($app, '/admin/pages/blog/post/edit')->getBody();
        self::assertStringNotContainsString('content-panels', $html);
        self::assertStringNotContainsString('badge-plugin', (string) $this->get($app, '/admin/pages')->getBody());
    }
}

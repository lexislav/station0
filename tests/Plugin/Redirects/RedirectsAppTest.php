<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use Delight\Auth\Role;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Station0\Plugin\Plugins;
use Station0\Plugin\Redirects\ForceMiddleware;
use Station0\Plugin\Redirects\Log;
use Station0\Plugin\Redirects\Redirector;
use Station0\Plugin\Redirects\Store;
use Station0\Tests\Plugin\BootsApp;
use Station0\Tests\Plugin\TestSite;

/** The bundled `redirects` plugin in the full app (see BootsApp). */
final class RedirectsAppTest extends TestCase
{
    use BootsApp;

    private const RULES = <<<'YAML'
        - from: /old-page
          to: /new-page
        - from: /blog/{year}/{slug}
          to: /articles/{slug}
        - from: ^/p/(\d+)$
          type: regex
          to: /products/$1
          status: 302
        - from: /discontinued
          status: 410
        - from: /about
          to: /shadowed-by-the-page
        YAML;

    private function app(?string $rules = self::RULES, array $options = [], ?int $role = Role::ADMIN): App
    {
        return $this->boot(['redirects' => $options], $role, null, function (TestSite $site) use ($rules) {
            $site->write('site/content/pages/about/photo.jpg', 'jpg');
            if ($rules !== null) {
                $site->write('site/content/_redirects.yaml', $rules);
            }
        });
    }

    /** Layers in the app's middleware stack (Slim keeps them as a chain of handlers). */
    private static function middlewareCount(App $app): int
    {
        $dispatcher = (new \ReflectionProperty(App::class, 'middlewareDispatcher'))->getValue($app);
        $layer = (new \ReflectionProperty($dispatcher, 'tip'))->getValue($dispatcher);
        $count = 0;
        while (is_object($layer) && (new \ReflectionObject($layer))->hasProperty('next')) {
            $count++;
            $layer = (new \ReflectionProperty($layer, 'next'))->getValue($layer);
        }
        return $count;
    }

    public function testDisabledPluginLeavesNoTrace(): void
    {
        $off = $this->boot([], Role::ADMIN, null, fn (TestSite $s) => $s->write('site/content/_redirects.yaml', self::RULES));
        $container = $off->getContainer();
        foreach ([Store::class, Log::class, Redirector::class, ForceMiddleware::class] as $id) {
            self::assertNotContains($id, $container->getKnownEntryNames());
        }
        self::assertSame(404, $this->get($off, '/old-page')->getStatusCode());
        self::assertFileDoesNotExist($this->site->dir . '/writable/cache/redirects.php');
        $baseline = self::middlewareCount($off);

        // Enabled without force rules: the middleware stack is the same.
        $on = $this->app();
        self::assertSame($baseline, self::middlewareCount($on));
        // With a force rule: exactly one more layer.
        $forced = $this->app(self::RULES . "\n- from: /quiet\n  to: /about\n  force: true\n");
        self::assertSame($baseline + 1, self::middlewareCount($forced));
    }

    public function testRulesAnswerTheMissingPaths(): void
    {
        $app = $this->app();

        $res = $this->get($app, '/old-page/?utm=x');
        self::assertSame(301, $res->getStatusCode());
        self::assertSame('/new-page?utm=x', $res->getHeaderLine('Location'));
        self::assertSame('public, max-age=3600', $res->getHeaderLine('Cache-Control'));

        self::assertSame('/articles/hello', $this->get($app, '/blog/2019/hello')->getHeaderLine('Location'));

        $temp = $this->get($app, '/p/42');
        self::assertSame(302, $temp->getStatusCode());
        self::assertSame('no-store', $temp->getHeaderLine('Cache-Control'));

        $gone = $this->get($app, '/discontinued');
        self::assertSame(410, $gone->getStatusCode());
        self::assertSame('not found /discontinued 410', (string) $gone->getBody());

        // A live page wins over a fallback rule from its path.
        self::assertSame(200, $this->get($app, '/about')->getStatusCode());
        self::assertSame(404, $this->get($app, '/nothing-here')->getStatusCode());
    }

    public function testForceRuleBeatsAnExistingPage(): void
    {
        $app = $this->app("- from: /quiet\n  to: /about\n  force: true\n");
        $res = $this->get($app, '/quiet');
        self::assertSame(301, $res->getStatusCode());
        self::assertSame('/about', $res->getHeaderLine('Location'));
        self::assertSame(200, $this->get($app, '/about')->getStatusCode());
    }

    public function testRenameAndMoveInTheAdminAddSubtreeRules(): void
    {
        $app = $this->app(null);

        $res = $this->post($app, '/admin/pages/about/update', ['title' => 'About', 'slug' => 'about-us', 'template' => 'page'], '/admin/pages/about/edit');
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/about-us', $this->get($app, '/about')->getHeaderLine('Location'));
        self::assertSame('/about-us/team', $this->get($app, '/about/team')->getHeaderLine('Location'));
        self::assertSame('/media/about-us/photo.jpg', $this->get($app, '/media/about/photo.jpg')->getHeaderLine('Location'));

        $res = $this->post($app, '/admin/pages/reorder', ['parent' => '/blog', 'order' => ['post', 'about-us'], 'moved' => ['from' => '/about-us']], '/admin/pages');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $rules = $app->getContainer()->get(Store::class)->load()['rules'];
        self::assertSame(['/about/{path*}', '/about-us/{path*}'], array_column($rules, 'from'));
        self::assertSame(['/blog/about-us/{path}', '/blog/about-us/{path}'], array_column($rules, 'to'));
        self::assertSame('Moved in admin by ed@example.com', $rules[1]['note']);
        self::assertSame('/blog/about-us', $this->get($app, '/about')->getHeaderLine('Location'));
        self::assertSame(200, $this->get($app, '/blog/about-us')->getStatusCode());
    }

    public function testAutoRulesCanBeTurnedOff(): void
    {
        $app = $this->app(null, ['auto' => false]);
        $this->post($app, '/admin/pages/about/update', ['title' => 'About', 'slug' => 'about-us', 'template' => 'page'], '/admin/pages/about/edit');
        self::assertSame(404, $this->get($app, '/about')->getStatusCode());
    }

    public function testNotFoundLogRecordsAndIgnoresNoise(): void
    {
        $app = $this->app();
        $this->get($app, '/missing?token=1', ['Referer' => 'https://ext.example/a?b=c']);
        $this->get($app, '/missing');
        $this->get($app, '/wp-login.php');
        $this->get($app, '/old-page');   // redirected, not a miss

        $entries = $app->getContainer()->get(Log::class)->entries();
        self::assertSame([['/missing', 2, 'https://ext.example/a']], array_map(fn ($e) => [$e['path'], $e['hits'], $e['referrer']], $entries));

        $html = (string) $this->get($this->reboot(), '/admin/redirects/404')->getBody();
        self::assertStringContainsString('<code>/missing</code>', $html);
        self::assertStringContainsString('/admin/redirects?from=%2Fmissing', $html);

        $off = $this->app(null, ['log404' => false]);
        $this->get($off, '/missing');
        self::assertSame([], $off->getContainer()->get(Log::class)->entries());
    }

    public function testAdminListFormAndTest(): void
    {
        $app  = $this->app();
        $html = (string) $this->get($app, '/admin/redirects?test=/blog/2020/x%3Fa%3D1')->getBody();
        self::assertStringContainsString('<code>/old-page</code>', $html);
        self::assertStringContainsString('Shadowed: a live page exists', $html);
        self::assertStringContainsString('<strong>301</strong> → <code>/articles/x?a=1</code>', $html);
        self::assertStringContainsString('data-nav="plugin-redirects"', $html);

        $res = $this->post($app, '/admin/redirects/rules', ['from' => '/x', 'to' => '/x'], '/admin/redirects');
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('redirect loop', (string) $res->getBody());

        $res = $this->post($app, '/admin/redirects/rules', ['from' => '/legacy/{a*}', 'to' => '/about'], '/admin/redirects');
        self::assertSame(303, $res->getStatusCode());
        self::assertSame('/about', $this->get($app, '/legacy/q/r')->getHeaderLine('Location'));
    }

    public function testEditorsManageExactRulesOnly(): void
    {
        $app  = $this->app(null, [], Role::EDITOR);
        $json = ['Accept' => 'application/json'];

        $ok = $this->post($app, '/admin/redirects/rules', ['from' => '/old-about', 'to' => '/about'], '/admin/pages/about/edit', $json);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $rule = json_decode((string) $ok->getBody(), true)['rule'];

        $no = $this->post($app, '/admin/redirects/rules', ['from' => '/x/{y}', 'to' => '/about'], '/admin/pages/about/edit', $json);
        self::assertSame(422, $no->getStatusCode());
        self::assertStringContainsString('Only an admin', (string) $no->getBody());

        // Panel + badge show the rule; the panel removes it through the JSON endpoint.
        $edit = (string) $this->get($app, '/admin/pages/about/edit')->getBody();
        self::assertStringContainsString('id="panel-redirects-redirects"', $edit);
        self::assertStringContainsString('<code>/old-about</code>', $edit);
        self::assertStringContainsString('↪ 1', (string) $this->get($app, '/admin/pages')->getBody());

        $del = $this->post($app, '/admin/redirects/rules/' . $rule['id'] . '/delete', [], '/admin/pages', $json);
        self::assertSame('{"ok":true}', (string) $del->getBody());
        self::assertStringNotContainsString('↪ 1', (string) $this->get($app, '/admin/pages')->getBody());
    }

    public function testPanelShowsSubtreeRulesAndBlockingForceRules(): void
    {
        $app = $this->app("- from: /team/{path*}\n  to: /blog/{path}\n  auto: '2026-10-26'\n- from: /blog/post\n  to: /about\n  force: true\n");
        $edit = (string) $this->get($app, '/admin/pages/blog/post/edit')->getBody();
        self::assertStringContainsString('<code>/team/post</code>', $edit);
        self::assertStringContainsString('A force rule redirects this page away', $edit);
        self::assertStringContainsString('↪ ✕', (string) $this->get($app, '/admin/pages')->getBody());

        $new = (string) $this->get($app, '/admin/pages/new')->getBody();
        self::assertStringContainsString('Available after the first save.', $new);
    }

    public function testConsoleCommands(): void
    {
        $app     = $this->app();
        $plugins = $app->getContainer()->get(Plugins::class);
        $run = function (string $name, array $args) use ($plugins): array {
            ob_start();
            $code = ($plugins->command($name)['handler'])($args);
            return [$code, ob_get_clean()];
        };

        self::assertSame([0, "302 /products/7?x=1   ← ^/p/(\\d+)$\n"], $run('redirects:test', ['/p/7?x=1']));
        self::assertSame(0, $run('redirects:add', ['/from-cli', '/about', '--status=308'])[0]);
        self::assertSame(308, $this->get($app, '/from-cli')->getStatusCode());
        [$code, $out] = $run('redirects:list', []);
        self::assertSame(0, $code);
        self::assertStringContainsString('308  /from-cli → /about', $out);
    }
}

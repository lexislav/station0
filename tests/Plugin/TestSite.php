<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

/**
 * A throwaway skeleton site in the temp dir: config, templates, a few pages
 * and a collection item — enough to boot the full app (PluginAppTest) or
 * bin/console (PluginConsoleTest).
 */
final class TestSite
{
    public readonly string $dir;

    public function __construct(private readonly array $plugins = [])
    {
        $this->dir = sys_get_temp_dir() . '/s0-site-' . bin2hex(random_bytes(6));
        foreach (['site/templates', 'site/content/pages/blog/post', 'site/content/pages/about',
            'site/content/pages/quiet', 'site/content/collections/products/chair', 'writable/cache',
            'writable/sessions', 'writable/logs', 'writable/uploads'] as $d) {
            @mkdir($this->dir . '/' . $d, 0775, true);
        }
        $this->write('site/templates/page.twig', '<h1>{{ page.title }}</h1>{{ content|raw }}|{{ fixture_hello() }}|{{ "x"|shout }}|{{ fixture.version ?? "" }}');
        $this->write('site/templates/404.twig', 'not found {{ urlPath }} {{ status }}');
        $this->write('site/content/pages/page.txt', "Title: Home\nStatus: published\n---\nHome body\n");
        $this->write('site/content/pages/blog/page.txt', "Title: Blog\nStatus: published\n---\nBlog\n");
        $this->write('site/content/pages/blog/post/page.txt', "Title: Post\nStatus: published\n---\nPost\n");
        $this->write('site/content/pages/about/page.txt', "Title: About\nStatus: published\n---\nAbout\n");
        $this->write('site/content/pages/quiet/page.txt', "Title: Quiet\nStatus: published\n---\nQuiet\n");
        $this->write('site/content/collections/products/chair/item.txt', "Title: Chair\nStatus: published\n---\n");
        $this->write('site/config.php', '<?php return fn (string $station0Root, string $siteRoot, string $projectRoot) => '
            . '\Station0\Tests\Plugin\TestSite::configFor(' . var_export($this->dir, true) . ', ' . var_export($this->plugins, true) . ');');
    }

    public function config(): array
    {
        return self::configFor($this->dir, $this->plugins);
    }

    public static function configFor(string $dir, array $plugins): array
    {
        $station0Root = dirname(__DIR__, 2);
        return [
            'name'         => 'Test site',
            'baseUrl'      => 'http://localhost',
            'adminPath'    => '/admin',
            'debug'        => true,
            'admin_locale' => 'en',
            'session'      => ['name' => 's0test', 'cookieLifetime' => 0, 'secure' => false, 'sameSite' => 'Lax'],
            'mail'         => ['from' => 'site@example.com', 'transport' => 'mail'],
            'plugins'      => $plugins,
            'paths'        => [
                'projectRoot'    => $station0Root,
                'templates'      => $dir . '/site/templates',
                'adminTemplates' => $station0Root . '/admin/templates',
                'content'        => $dir . '/site/content',
                'cache'          => $dir . '/writable/cache',
                'sessions'       => $dir . '/writable/sessions',
                'logs'           => $dir . '/writable/logs',
                'uploads'        => $dir . '/writable/uploads',
                'db'             => $dir . '/writable/station0.sqlite',
                'tasks'          => $dir . '/site/tasks',
                'actions'        => $dir . '/site/actions',
            ],
        ];
    }

    public function write(string $path, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $path), 0775, true);
        file_put_contents($this->dir . '/' . $path, $content);
    }

    public function remove(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }
}

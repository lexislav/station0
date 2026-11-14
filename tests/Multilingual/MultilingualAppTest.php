<?php

declare(strict_types=1);

namespace Station0\Tests\Multilingual;

use Delight\Auth\Role;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Station0\Tests\Plugin\BootsApp;
use Station0\Tests\Plugin\TestSite;

/** Multilingual content in the full app: routing, Twig helpers, cache, access, redirects, admin. */
final class MultilingualAppTest extends TestCase
{
    use BootsApp;

    private const TEMPLATE = '<html lang="{{ lang }}"><h1>{{ page.title }}</h1>{{ content|raw }}'
        . '|nav:{% for p in nav_pages() %}<a href="{{ url(p) }}">{{ p.title }}</a>{% endfor %}'
        . '|alt:{% for l in translations(page) %}[{{ l.code }} {{ l.url }}{{ l.current ? " *" : "" }}]{% endfor %}'
        . '|t:{{ t("greeting", {name: "Eva"}) }}/{{ t("only.default") }}/{{ t("missing.key") }}';

    private function app(?int $role = null, array $config = [], array $plugins = [], ?\Closure $prepare = null): App
    {
        return $this->boot($plugins, $role, self::TEMPLATE, function (TestSite $site) use ($config, $prepare): void {
            $site->configure($config + ['languages' => ['cs' => ['label' => 'Čeština'], 'en' => ['label' => 'English']]]);
            $site->write('site/content/pages/page.txt', "Title: Domů\nStatus: published\n---\nVítejte\n");
            $site->write('site/content/pages/page.en.txt', "Title: Home\n---\nWelcome\n");
            $site->write('site/content/pages/about/page.en.txt', "Title: About us\n---\nIn English\n");
            $site->write('site/content/pages/blog/page.en.txt', "Title: Blog EN\n---\n");
            $site->write('site/content/pages/blog/post/page.en.txt', "Title: Post EN\nStatus: draft\n---\n");
            $site->write('site/lang/cs.yaml', "greeting: Ahoj {name}\nonly:\n  default: Jen česky\n");
            $site->write('site/lang/en.yaml', "greeting: Hello {name}\n");
            $prepare?->__invoke($site);
        });
    }

    public function testDefaultLanguageIsUnprefixed(): void
    {
        $html = (string) $this->get($this->app(), '/about')->getBody();
        self::assertStringContainsString('<html lang="cs"><h1>About</h1>', $html);
        self::assertStringContainsString('[cs /about *][en /en/about]', $html);
        self::assertStringContainsString('|t:Ahoj Eva/Jen česky/missing.key', $html);
    }

    public function testPrefixedLanguage(): void
    {
        $app  = $this->app();
        $html = (string) $this->get($app, '/en/about')->getBody();
        self::assertStringContainsString('<html lang="en"><h1>About us</h1><p>In English</p>', $html);
        self::assertStringContainsString('[cs /about][en /en/about *]', $html);
        self::assertStringContainsString('|t:Hello Eva/Jen česky/missing.key', $html, 'site strings fall back to the default language');
        // nav: links stay in the language, untranslated pages (quiet) are left out
        self::assertStringContainsString('<a href="/en/about">About us</a>', $html);
        self::assertStringContainsString('<a href="/en/blog">Blog EN</a>', $html);
        self::assertStringNotContainsString('Quiet', $html);
    }

    public function testHomeInLanguage(): void
    {
        $html = (string) $this->get($this->app(), '/en')->getBody();
        self::assertStringContainsString('<h1>Home</h1><p>Welcome</p>', $html);
    }

    public function testUntranslatedAndDraftTranslationAre404(): void
    {
        $app = $this->app();
        self::assertSame(404, $this->get($app, '/en/quiet')->getStatusCode());
        self::assertSame(200, $this->get($this->app(), '/quiet')->getStatusCode());
        self::assertSame(404, $this->get($this->app(), '/en/blog/post')->getStatusCode(), 'draft translation');
        self::assertSame(200, $this->get($this->app(), '/blog/post')->getStatusCode());
        self::assertSame(404, $this->get($this->app(), '/cs/about')->getStatusCode(), 'no prefix for the default language');
    }

    public function testDraftTranslationPreviewForEditors(): void
    {
        $res = $this->get($this->app(Role::EDITOR), '/en/blog/post');
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('station0-preview-bar', (string) $res->getBody());
    }

    public function testContentFallbackMode(): void
    {
        $html = (string) $this->get($this->app(null, ['languageFallback' => 'content']), '/en/quiet')->getBody();
        self::assertStringContainsString('<html lang="en"><h1>Quiet</h1>', $html);
    }

    public function testRenderCacheIsPerLanguage(): void
    {
        $app = $this->app();
        self::assertStringContainsString('In English', (string) $this->get($app, '/en/about')->getBody());
        $this->reboot(null);
        $cs = (string) $this->get($this->reboot(null), '/about')->getBody();
        self::assertStringContainsString('<h1>About</h1><p>About</p>', $cs);
        self::assertStringNotContainsString('In English', $cs);
    }

    public function testEditingTranslationInvalidatesCache(): void
    {
        $this->get($this->app(), '/en/about');
        $file = $this->site->dir . '/site/content/pages/about/page.en.txt';
        file_put_contents($file, "Title: About us\n---\nChanged\n");
        touch($file, time() + 5);
        self::assertStringContainsString('Changed', (string) $this->get($this->reboot(null), '/en/about')->getBody());
    }

    public function testNotMultilingualSiteIgnoresPrefix(): void
    {
        $app = $this->boot([], null, self::TEMPLATE, function (TestSite $site): void {
            $site->write('site/content/pages/about/page.en.txt', "Title: About us\n---\n");
        });
        self::assertSame(404, $this->get($app, '/en/about')->getStatusCode());
        $html = (string) $this->get($this->reboot(null), '/about')->getBody();
        self::assertStringContainsString('<html lang=""><h1>About</h1>', $html);
        self::assertStringContainsString('|alt:|', $html);
    }

    public function testMembersOnlySectionStaysGatedInEveryLanguage(): void
    {
        // Access: is structure — read from the default file, even when the
        // section page itself is not translated.
        $app = $this->app(null, ['access' => ['mode' => 'public', 'redirect' => '/']], [], function (TestSite $site): void {
            $site->write('site/content/pages/quiet/page.txt', "Title: Quiet\nStatus: published\nAccess: members\n---\n");
            $site->write('site/content/pages/quiet/inner/page.txt', "Title: Inner\nStatus: published\n---\n");
            $site->write('site/content/pages/quiet/inner/page.en.txt', "Title: Inner EN\nAccess: public\n---\n");
            $site->write('site/content/pages/quiet/inner/photo.jpg', 'jpg');
        });
        $res = $this->get($app, '/en/quiet/inner');
        self::assertNotSame(200, $res->getStatusCode());
        self::assertNotSame(200, $this->get($this->reboot(null), '/quiet/inner')->getStatusCode());
        self::assertSame(200, $this->get($this->reboot(Role::ADMIN), '/en/quiet/inner')->getStatusCode());
    }

    public function testTranslationSourceIsNotServedAsMedia(): void
    {
        $app = $this->app();
        self::assertSame(404, $this->get($app, '/media/about/page.en.txt')->getStatusCode());
        self::assertSame(404, $this->get($this->reboot(null), '/en/media/about/page.en.txt')->getStatusCode());
    }

    public function testAdminIsNeverPrefixed(): void
    {
        self::assertSame(404, $this->get($this->app(Role::ADMIN), '/en/admin/pages')->getStatusCode());
    }

    public function testRedirectRulesKeepTheLanguage(): void
    {
        $app = $this->app(null, [], ['redirects' => []], function (TestSite $site): void {
            $site->write('site/content/_redirects.yaml', "- from: /old\n  to: /about\n- from: /ext\n  to: https://example.com/x\n");
        });
        self::assertSame('/en/about', $this->get($app, '/en/old')->getHeaderLine('Location'));
        self::assertSame('/about', $this->get($this->reboot(null), '/old')->getHeaderLine('Location'));
        self::assertSame('https://example.com/x', $this->get($this->reboot(null), '/en/ext')->getHeaderLine('Location'));
    }

    public function testAdminEditsTranslation(): void
    {
        $app  = $this->app(Role::ADMIN);
        $html = (string) $this->get($app, '/admin/pages/quiet/edit?lang=en')->getBody();
        self::assertStringContainsString('class="lang-tabs"', $html);
        self::assertStringContainsString('lang-untranslated', $html);
        self::assertStringContainsString('/admin/pages/quiet/update?lang=en', $html);
        self::assertStringNotContainsString('<input type="text" name="slug"', $html, 'slug is edited in the default language');
        self::assertStringNotContainsString('name="visibility_listing"', $html);

        $res = $this->post($app, '/admin/pages/quiet/update?lang=en', [
            'title' => 'Quiet EN', 'body' => "Hush\n", 'visibility_status' => 'published',
        ], '/admin/pages/quiet/edit?lang=en');
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/pages/quiet/edit?lang=en', $res->getHeaderLine('Location'));
        $file = $this->site->dir . '/site/content/pages/quiet/page.en.txt';
        self::assertStringContainsString('Title: Quiet EN', file_get_contents($file));
        self::assertStringContainsString('Title: Quiet', file_get_contents($this->site->dir . '/site/content/pages/quiet/page.txt'));

        self::assertStringContainsString('<h1>Quiet EN</h1><p>Hush</p>', (string) $this->get($this->reboot(null), '/en/quiet')->getBody());

        // The tree shows the translation status.
        $list = (string) $this->get($this->reboot(Role::ADMIN), '/admin/pages')->getBody();
        self::assertMatchesRegularExpression('#class="badge-lang" href="/admin/pages/quiet/edit\?lang=en"#', $list);

        // Delete just the translation.
        $app = $this->reboot(Role::ADMIN);
        $res = $this->post($app, '/admin/pages/quiet/delete?lang=en', [], '/admin/pages/quiet/edit?lang=en');
        self::assertSame(302, $res->getStatusCode());
        self::assertFileDoesNotExist($file);
        self::assertFileExists($this->site->dir . '/site/content/pages/quiet/page.txt');
    }

    public function testDefaultLanguageEditFormIsUnchangedButTabbed(): void
    {
        $html = (string) $this->get($this->app(Role::ADMIN), '/admin/pages/about/edit')->getBody();
        self::assertStringContainsString('class="lang-tabs"', $html);
        self::assertStringContainsString('<input type="text" name="slug"', $html);
        self::assertStringContainsString('/admin/pages/about/update"', $html);
    }
}

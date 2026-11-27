<?php

declare(strict_types=1);

namespace Station0\Tests\Multilingual;

use Delight\Auth\Role;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Station0\Plugin\Redirects\Compiler;
use Station0\Plugin\Redirects\Matcher;
use Station0\Plugin\Redirects\Rules;
use Station0\Service\ContentRepository;
use Station0\Service\Languages;
use Station0\Tests\Plugin\BootsApp;
use Station0\Tests\Plugin\TestSite;

/** `Slug:` in a translation file: routing, URLs, 301 from the folder slug, admin, redirects. */
final class TranslatedSlugsTest extends TestCase
{
    use BootsApp;

    private const TEMPLATE = '<h1>{{ page.title }}</h1>'
        . '|nav:{% for p in nav_pages() %}<a href="{{ url(p) }}">{{ p.title }}</a>{% endfor %}'
        . '|alt:{% for l in translations(page) %}[{{ l.code }} {{ l.url }}]{% endfor %}'
        . '|str:{{ url("/o-nas/tym") }}|{{ url("/o-nas?x=1") }}|{{ url(page, "cs") }}';

    private function app(?int $role = null, array $plugins = [], ?\Closure $prepare = null, array $config = []): App
    {
        return $this->boot($plugins, $role, self::TEMPLATE, function (TestSite $site) use ($prepare, $config): void {
            $site->configure($config + ['languages' => ['cs' => [], 'en' => []]]);
            $site->write('site/content/pages/o-nas/page.txt', "Title: O nás\nStatus: published\n---\nČesky\n");
            $site->write('site/content/pages/o-nas/page.en.txt', "Title: About us\nSlug: about-us\n---\nEnglish\n");
            $site->write('site/content/pages/o-nas/tym/page.txt', "Title: Tým\nStatus: published\n---\n");
            $site->write('site/content/pages/o-nas/tym/page.en.txt', "Title: Team\nSlug: team\n---\n");
            $site->write('site/content/pages/o-nas/historie/page.txt', "Title: Historie\nStatus: published\n---\n");
            $site->write('site/content/pages/o-nas/historie/page.en.txt', "Title: History\n---\n");
            $site->write('site/content/pages/o-nas/archiv/page.txt', "Title: Archiv\nStatus: published\n---\n");
            $prepare?->__invoke($site);
        });
    }

    // ── Repository ───────────────────────────────────────────────────────

    public function testPublicPathsAndResolution(): void
    {
        $this->app();
        $languages = new Languages(['cs' => [], 'en' => []]);
        $repo = new ContentRepository($this->site->dir . '/site/content/pages', null, null, $languages);
        $en   = $repo->in('en');

        self::assertSame('/about-us/team', $en->find('/o-nas/tym')->publicPath);
        self::assertSame('/about-us/historie', $en->find('/o-nas/historie')->publicPath, 'untranslated slug = folder slug');
        self::assertSame('/o-nas/tym', $repo->in('cs')->find('/o-nas/tym')->publicPath);
        self::assertSame('team', $en->find('/o-nas/tym')->translatedSlug);
        self::assertArrayNotHasKey('slug', $en->find('/o-nas')->extra);

        self::assertSame('/o-nas/tym', $en->resolvePublicPath('/about-us/team'));
        self::assertSame('/o-nas/tym', $en->resolvePublicPath('/o-nas/team'), 'folder slugs still resolve (then 301)');
        self::assertSame('/o-nas/historie', $en->resolvePublicPath('/about-us/historie'));
        self::assertNull($en->resolvePublicPath('/about-us/nope'));
        self::assertNull($en->resolvePublicPath('/about-us/../o-nas'));
        self::assertSame('/about-us', $repo->in('cs')->resolvePublicPath('/about-us'), 'default language: paths are folder paths');
        self::assertSame('/about-us/team', $en->publicPathOf('/o-nas/tym'));
        self::assertSame('/contact/send', $en->publicPathOf('/contact/send'));
        // A page without a translation, and a path that is no page, keep their
        // ancestors' slugs instead of falling back to the whole folder path.
        self::assertSame('/about-us/archiv', $en->publicPathOf('/o-nas/archiv'), 'untranslated page under a translated parent');
        self::assertSame('/about-us/team/nope', $en->publicPathOf('/o-nas/tym/nope'), 'no page there');
        self::assertSame('/nope/tym', $en->publicPathOf('/nope/tym'), 'nothing below a missing folder is translated');
        self::assertSame('/about-us/../o-nas', $en->publicPathOf('/o-nas/../o-nas'), 'traversal segments are kept, never looked up');
        self::assertSame('/o-nas/archiv', $repo->in('cs')->publicPathOf('/o-nas/archiv'));

        $page = $en->find('/o-nas/historie');
        self::assertTrue($en->translatedSlugAvailable($page, 'history'));
        self::assertFalse($en->translatedSlugAvailable($page, 'team'), 'a sibling uses it in English');
        self::assertFalse($en->translatedSlugAvailable($page, 'tym'), 'a sibling folder');
        self::assertTrue($en->translatedSlugAvailable($page, 'historie'), 'its own folder slug');
        $top = $en->find('/o-nas');
        self::assertFalse($en->translatedSlugAvailable($top, 'en'), 'a language code at the top level');
        self::assertFalse($en->translatedSlugAvailable($top, 'blog'));
    }

    public function testSavingASlugRebuildsTheLookup(): void
    {
        $this->app();
        $repo = new ContentRepository($this->site->dir . '/site/content/pages', null, null, new Languages(['cs' => [], 'en' => []]));
        $en   = $repo->in('en');
        self::assertSame('/o-nas/historie', $en->resolvePublicPath('/about-us/historie'));
        $page = $en->find('/o-nas/historie');
        $page->translatedSlug = 'history';
        $en->saveTranslation($page);
        self::assertSame('/about-us/history', $page->publicPath);
        self::assertStringContainsString("Slug: history\n", file_get_contents($this->site->dir . '/site/content/pages/o-nas/historie/page.en.txt'));
        self::assertSame('/o-nas/historie', $en->resolvePublicPath('/about-us/history'));
        self::assertSame('/about-us/history', $en->find('/o-nas/historie')->publicPath);
    }

    // ── Public site ──────────────────────────────────────────────────────

    public function testTranslatedUrlServesThePage(): void
    {
        $res  = $this->get($this->app(), '/en/about-us');
        $html = (string) $res->getBody();
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('<h1>About us</h1>', $html);
        self::assertStringContainsString('<a href="/en/about-us">About us</a>', $html, 'nav links use translated slugs');
        self::assertStringContainsString('[cs /o-nas][en /en/about-us]', $html);
        self::assertStringContainsString('|str:/en/about-us/team|/en/about-us?x=1|/o-nas', $html, 'url() maps folder paths');

        $html = (string) $this->get($this->reboot(null), '/en/about-us/team')->getBody();
        self::assertStringContainsString('<h1>Team</h1>', $html);
        self::assertStringContainsString('[cs /o-nas/tym][en /en/about-us/team]', $html);
    }

    public function testUrlLeavesAssetsAdminAndRelativeLinksAlone(): void
    {
        $tpl = '<h1>{{ page.title }}</h1>|u:'
            . '{{ url("/media/o-nas/doc.pdf") }} {{ url("/thumb/400x0/abc/o-nas/a.jpg") }} {{ url("/admin/pages") }}'
            . ' {{ url("foo/bar") }} {{ url("#top") }} {{ url("?page=2") }} {{ url("mailto:a@b.cz") }}'
            . ' {{ url("https://example.com/x") }} {{ url("//cdn.example.com/x") }} {{ url("/en/o-nas") }}'
            . ' {{ url("/o-nas/archiv") }} {{ url("/o-nas/tym#a") }} {{ url("") }} {{ url("/media", "cs") }}|';
        $app = $this->boot([], null, $tpl, function (TestSite $site): void {
            $site->configure(['languages' => ['cs' => [], 'en' => []]]);
            $site->write('site/content/pages/o-nas/page.txt', "Title: O nás\nStatus: published\n---\n");
            $site->write('site/content/pages/o-nas/page.en.txt', "Title: About us\nSlug: about-us\n---\n");
            $site->write('site/content/pages/o-nas/tym/page.txt', "Title: Tým\nStatus: published\n---\n");
            $site->write('site/content/pages/o-nas/tym/page.en.txt', "Title: Team\nSlug: team\n---\n");
            $site->write('site/content/pages/o-nas/archiv/page.txt', "Title: Archiv\nStatus: published\n---\n");
        });
        $html = (string) $this->get($app, '/en/about-us')->getBody();
        self::assertStringContainsString(
            '|u:/media/o-nas/doc.pdf /thumb/400x0/abc/o-nas/a.jpg /admin/pages'
            . ' foo/bar #top ?page=2 mailto:a@b.cz'
            . ' https://example.com/x //cdn.example.com/x /en/o-nas'
            . ' /en/about-us/archiv /en/about-us/team#a /en /media|',
            $html,
        );
    }

    public function testDefaultLanguageIsUnchanged(): void
    {
        $app = $this->app();
        self::assertStringContainsString('<h1>O nás</h1>', (string) $this->get($app, '/o-nas')->getBody());
        self::assertSame(404, $this->get($this->reboot(null), '/about-us')->getStatusCode());
        self::assertSame(200, $this->get($this->reboot(null), '/o-nas/')->getStatusCode(), 'no canonical redirect in the default language');
    }

    public function testFolderSlugRedirectsToTheTranslatedOne(): void
    {
        $app = $this->app();
        $res = $this->get($app, '/en/o-nas?ref=x');
        self::assertSame(301, $res->getStatusCode());
        self::assertSame('/en/about-us?ref=x', $res->getHeaderLine('Location'));
        self::assertSame('/en/about-us/team', $this->get($this->reboot(null), '/en/o-nas/tym')->getHeaderLine('Location'));
        self::assertSame('/en/about-us/team', $this->get($this->reboot(null), '/en/about-us/tym')->getHeaderLine('Location'));
        self::assertSame(200, $this->get($this->reboot(null), '/en/about-us/')->getStatusCode(), 'a trailing slash is not a different path');
    }

    public function testNonLivePageIsNotRedirected(): void
    {
        $app = $this->app(null, [], function (TestSite $site): void {
            $site->write('site/content/pages/o-nas/tym/page.en.txt', "Title: Team\nSlug: team\nStatus: draft\n---\n");
        });
        self::assertSame(404, $this->get($app, '/en/about-us/tym')->getStatusCode(), 'no slug leak for a hidden page');
        self::assertSame(404, $this->get($this->reboot(null), '/en/about-us/team')->getStatusCode());
    }

    public function testAccessUsesTheFolderPath(): void
    {
        $app = $this->app(null, [], function (TestSite $site): void {
            $site->write('site/content/pages/o-nas/page.txt', "Title: O nás\nStatus: published\nAccess: members\n---\n");
        }, ['access' => ['mode' => 'public', 'redirect' => '/']]);
        $res = $this->get($app, '/en/about-us/team');
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/en', $res->getHeaderLine('Location'));
    }

    public function testActionRedirectGetsTheTranslatedPath(): void
    {
        $app = $this->app(Role::ADMIN, [], function (TestSite $site): void {
            $site->write('site/actions/send.php', "<?php return ['path' => '/contact/send', 'handler' => fn (\\Station0\\Service\\ActionContext \$ctx) => \$ctx->redirect('/o-nas/tym?sent=1')];");
        });
        $res = $this->post($app, '/en/contact/send', [], '/admin/pages/o-nas/edit');
        self::assertSame('/en/about-us/team?sent=1', $res->getHeaderLine('Location'));
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function testAdminSavesAndValidatesTheSlug(): void
    {
        $app  = $this->app(Role::ADMIN);
        $html = (string) $this->get($app, '/admin/pages/o-nas/historie/edit?lang=en')->getBody();
        self::assertStringContainsString('<input type="text" name="slug" value="" placeholder="historie">', $html);

        $form = '/admin/pages/o-nas/historie/edit?lang=en';
        $res  = $this->post($app, '/admin/pages/o-nas/historie/update?lang=en',
            ['title' => 'History', 'slug' => 'Team', 'visibility_status' => 'published'], $form);
        self::assertSame(422, $res->getStatusCode(), 'the sibling uses "team" in English');

        $app = $this->reboot(Role::ADMIN);
        $res = $this->post($app, '/admin/pages/o-nas/historie/update?lang=en',
            ['title' => 'History', 'slug' => 'Our History', 'visibility_status' => 'published'], $form);
        self::assertSame(302, $res->getStatusCode());
        $file = $this->site->dir . '/site/content/pages/o-nas/historie/page.en.txt';
        self::assertStringContainsString('Slug: our-history', file_get_contents($file));
        self::assertSame(200, $this->get($this->reboot(null), '/en/about-us/our-history')->getStatusCode());

        // Empty = back to the folder slug.
        $app = $this->reboot(Role::ADMIN);
        $this->post($app, '/admin/pages/o-nas/historie/update?lang=en',
            ['title' => 'History', 'slug' => '', 'visibility_status' => 'published'], $form);
        self::assertStringNotContainsString('Slug:', file_get_contents($file));
    }

    public function testRenamedSlugAddsALanguageRule(): void
    {
        $app = $this->app(Role::ADMIN, ['redirects' => []]);
        $res = $this->post($app, '/admin/pages/o-nas/update?lang=en',
            ['title' => 'About us', 'slug' => 'who-we-are', 'visibility_status' => 'published'], '/admin/pages/o-nas/edit?lang=en');
        self::assertSame(302, $res->getStatusCode());
        $yaml = file_get_contents($this->site->dir . '/site/content/_redirects.yaml');
        self::assertStringContainsString('lang: en', $yaml);

        $app = $this->reboot(null);
        self::assertSame('/en/who-we-are/team', $this->get($app, '/en/about-us/team')->getHeaderLine('Location'));
        self::assertSame('/en/who-we-are', $this->get($this->reboot(null), '/en/about-us')->getHeaderLine('Location'));
        self::assertSame(404, $this->get($this->reboot(null), '/about-us')->getStatusCode(), 'the rule is English only');
    }

    // ── Redirects plugin ─────────────────────────────────────────────────

    public function testLanguageRulesAndNeutralTargets(): void
    {
        $app = $this->app(null, ['redirects' => []], function (TestSite $site): void {
            $site->write('site/content/_redirects.yaml', "- from: /company\n  to: /about-us/team\n  lang: en\n"
                . "- from: /old-team\n  to: /o-nas/tym\n");
        });
        self::assertSame('/en/about-us/team', $this->get($app, '/en/company')->getHeaderLine('Location'));
        self::assertSame(404, $this->get($this->reboot(null), '/company')->getStatusCode());
        self::assertSame('/en/about-us/team', $this->get($this->reboot(null), '/en/old-team')->getHeaderLine('Location'), 'neutral target: folder path, translated');
        self::assertSame('/o-nas/tym', $this->get($this->reboot(null), '/old-team')->getHeaderLine('Location'));
    }

    public function testNotFoundLogKeepsTheLanguage(): void
    {
        $app = $this->app(null, ['redirects' => []]);
        $this->get($app, '/en/missing');
        $this->get($this->reboot(null), '/missing');
        $pdo   = new \PDO('sqlite:' . $this->site->config()['paths']['db']);
        $paths = $pdo->query('SELECT path FROM station0_404 ORDER BY path')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['/en/missing', '/missing'], $paths);
    }

    public function testRuleNormalizationWithLang(): void
    {
        $rule = Rules::normalize(['from' => '/a', 'to' => '/b', 'lang' => 'EN']);
        self::assertSame('en', $rule['lang']);
        self::assertNotSame(Rules::normalize(['from' => '/a', 'to' => '/b'])['id'], $rule['id']);
        self::assertSame(['from' => '/a', 'to' => '/b', 'lang' => 'en'], Rules::toYaml($rule));
        self::assertNull(Rules::normalize(['from' => '/a', 'to' => '/b'])['lang']);
        self::assertContains('A rule with "lang" cannot be force.', Rules::validate(Rules::normalize(['from' => '/a', 'to' => '/b', 'lang' => 'en', 'force' => true])));

        $matcher = new Matcher(Compiler::compile([
            $rule,
            Rules::normalize(['from' => '/a', 'to' => '/neutral']),
            Rules::normalize(['from' => '/p/{x}', 'to' => '/q/{x}', 'lang' => 'en']),
        ]));
        self::assertSame('/b', $matcher->match('/a', '', false, 'en')['location']);
        self::assertSame('/neutral', $matcher->match('/a', '', false, 'de')['location']);
        self::assertSame('/neutral', $matcher->match('/a')['location']);
        self::assertSame('/q/1', $matcher->match('/folder', '', false, 'en', '/p/1')['location'], 'lang rules match the public path');
        self::assertNull($matcher->match('/p/1'));
    }
}

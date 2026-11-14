<?php

declare(strict_types=1);

namespace Station0\Tests\Multilingual;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\Languages;

final class ContentRepositoryTranslationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-ml-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/pages', 0775, true);
        mkdir($this->dir . '/templates', 0775, true);
        $this->write('page.txt', "Title: Domů\nStatus: published\n---\nVítejte\n");
        $this->write('page.en.txt', "Title: Home\n---\nWelcome\n");
        $this->write('about/page.txt', "Title: O nás\nStatus: published\nSort: 2\nListing: nav-hidden\nAccess: members\nPrice: 100\nSubtitle: Podtitul\n---\nČesky\n");
        $this->write('about/page.en.txt', "Title: About us\nSort: 9\nListing: unlisted\nAccess: public\nPrice: 999\nSubtitle: Subtitle\n---\n");
        $this->write('about/team/page.txt', "Title: Tým\nStatus: published\n---\nTým\n");
        $this->write('about/team/page.en.txt', "Title: Team\nStatus: draft\n---\nTeam\n");
        $this->write('quiet/page.txt', "Title: Ticho\nStatus: published\n---\nJen česky\n");
        $this->write('quiet/inner/page.txt', "Title: Uvnitř\nStatus: published\n---\n");
        $this->write('quiet/inner/page.en.txt', "Title: Inside\n---\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function write(string $path, string $content): void
    {
        @mkdir(dirname($this->dir . '/pages/' . $path), 0775, true);
        file_put_contents($this->dir . '/pages/' . $path, $content);
    }

    private function repo(string $fallback = '404', array $shared = ['price']): ContentRepository
    {
        return new ContentRepository(
            $this->dir . '/pages',
            $this->dir . '/templates',
            null,
            new Languages(['cs' => [], 'en' => []], $fallback),
            fn (string $template): array => $shared,
        );
    }

    public function testDefaultLanguageReadsDefaultFileAndListsTranslations(): void
    {
        $about = $this->repo()->find('/about');
        self::assertSame('O nás', $about->title);
        self::assertSame('cs', $about->lang);
        self::assertTrue($about->translated);
        self::assertNull($about->translationFile);
        self::assertSame(['cs', 'en'], $about->translations);
        self::assertSame(['cs'], $this->repo()->find('/quiet')->translations);
        self::assertStringEndsWith('/about/page.txt', $about->filePath, 'translation is never the content file');
    }

    public function testTranslationMergesOverDefaultExceptStructureAndSharedFields(): void
    {
        $about = $this->repo()->in('en')->find('/about');
        self::assertSame('About us', $about->title);
        self::assertSame('en', $about->lang);
        self::assertStringEndsWith('/about/page.en.txt', $about->translationFile);
        self::assertSame("Česky\n", $about->body, 'empty translated body falls back');
        self::assertSame('published', $about->status, 'status inherited when the translation has none');
        self::assertSame(2, $about->sort);
        self::assertSame('nav-hidden', $about->listing);
        self::assertSame('members', $about->extra['access']);
        self::assertSame('100', $about->extra['price'], 'translate: false field');
        self::assertSame('Subtitle', $about->extra['subtitle']);
    }

    public function testStatusPerTranslation(): void
    {
        $en = $this->repo()->in('en');
        self::assertFalse($en->find('/about/team')->isLive());
        self::assertTrue($this->repo()->find('/about/team')->isLive());
        self::assertNotContains('/about/team', array_map(fn ($p) => $p->urlPath, $en->children('/about', true)));
    }

    public function testUntranslatedPageIs404InFallback404Mode(): void
    {
        $en = $this->repo()->in('en');
        self::assertNull($en->find('/quiet'));
        // Its translated sub-page still exists; the parent acts as structure.
        $inner = $en->find('/quiet/inner');
        self::assertSame('Inside', $inner->title);
        self::assertSame('Ticho', $inner->parent->title);
        self::assertFalse($inner->parent->translated);

        $paths = array_map(fn ($p) => $p->urlPath, $en->all());
        self::assertNotContains('/quiet', $paths);
        self::assertContains('/quiet/inner', $paths);
        self::assertNotContains('/quiet', array_map(fn ($p) => $p->urlPath, $en->navChildren('/')));
    }

    public function testContentFallbackModeServesDefaultText(): void
    {
        $quiet = $this->repo('content')->in('en')->find('/quiet');
        self::assertSame('Ticho', $quiet->title);
        self::assertFalse($quiet->translated);
        self::assertSame('en', $quiet->lang);
        self::assertNull($quiet->translationFile);
    }

    public function testAdminViewWithFallbackShowsUntranslatedPages(): void
    {
        $quiet = $this->repo()->in('en', true)->find('/quiet');
        self::assertNotNull($quiet);
        self::assertFalse($quiet->translated);
    }

    public function testCurrentLanguageComesFromLanguagesService(): void
    {
        $languages = new Languages(['cs' => [], 'en' => []]);
        $repo = new ContentRepository($this->dir . '/pages', null, null, $languages);
        self::assertSame('Domů', $repo->find('/')->title);
        $languages->setCurrent('en');
        self::assertSame('Home', $repo->find('/')->title);
        self::assertSame('Domů', $repo->in('')->find('/')->title, "in('') = the default language");
    }

    public function testSaveTranslationWritesOnlyTheText(): void
    {
        $repo = $this->repo();
        $quiet = $repo->in('en', true)->find('/quiet');
        $quiet->title = 'Quiet';
        $quiet->body  = "Only English\n";
        $quiet->extra['price']    = '5';
        $quiet->extra['subtitle'] = 'Sub';
        $quiet->status = 'draft';
        $repo->save($quiet); // routes to saveTranslation (page read in en)

        $file = $this->dir . '/pages/quiet/page.en.txt';
        self::assertFileExists($file);
        $raw = file_get_contents($file);
        self::assertStringContainsString('Title: Quiet', $raw);
        self::assertStringContainsString('Status: draft', $raw);
        self::assertStringContainsString('Subtitle: Sub', $raw);
        self::assertStringNotContainsString('Price', $raw);
        self::assertStringNotContainsString('Template', $raw);
        self::assertStringContainsString('Only English', $raw);
        self::assertStringContainsString("Title: Ticho", file_get_contents($this->dir . '/pages/quiet/page.txt'), 'default untouched');
        self::assertTrue($quiet->translated);
        self::assertContains('en', $quiet->translations);

        $fresh = $this->repo('404')->in('en', true)->find('/quiet');
        self::assertSame('Quiet', $fresh->title);
        self::assertSame('draft', $fresh->status);
    }

    public function testDefaultSaveIgnoresTranslationAndRenameMovesIt(): void
    {
        $repo  = $this->repo();
        $about = $repo->find('/about');
        $about->title = 'O nás 2';
        $repo->save($about);
        self::assertStringContainsString('Title: About us', file_get_contents($this->dir . '/pages/about/page.en.txt'));

        $en = $repo->in('en')->find('/about');
        $repo->rename($en, 'company');
        self::assertFileExists($this->dir . '/pages/company/page.en.txt');
        self::assertStringEndsWith('/company/page.en.txt', $en->translationFile);
        self::assertSame('About us', $this->repo()->in('en')->find('/company')->title);
    }

    public function testDeleteTranslationKeepsPage(): void
    {
        $repo  = $this->repo();
        $about = $repo->find('/about');
        self::assertTrue($repo->deleteTranslation($about, 'en'));
        self::assertFileDoesNotExist($this->dir . '/pages/about/page.en.txt');
        self::assertFalse($repo->deleteTranslation($about, 'cs'), 'cannot delete the default language');
        self::assertNotNull($this->repo()->find('/about'));
        self::assertNull($this->repo()->in('en')->find('/about'));
    }

    public function testDeletePageRemovesTranslations(): void
    {
        $repo = $this->repo();
        self::assertTrue($repo->delete('/about/team'));
        self::assertFileDoesNotExist($this->dir . '/pages/about/team/page.en.txt');
    }

    public function testMtimeCoversTranslation(): void
    {
        touch($this->dir . '/pages/about/page.txt', 1000);
        touch($this->dir . '/pages/about/page.en.txt', 2000);
        self::assertSame(1000, $this->repo()->mtime('/about'));
        self::assertSame(2000, $this->repo()->in('en')->mtime('/about'));
    }

    public function testNotMultilingualSiteIsUnchanged(): void
    {
        // Without languages `page.en.txt` is just another .txt — the historic
        // "several .txt files" rule picks page.txt.
        $repo = new ContentRepository($this->dir . '/pages', $this->dir . '/templates');
        $about = $repo->find('/about');
        self::assertSame('O nás', $about->title);
        self::assertSame('', $about->lang);
        self::assertSame([], $about->translations);
        self::assertSame($repo, $repo->in('en'));
    }

    public function testTranslationFileIsNotAContentFileOfItsOwn(): void
    {
        // A dir with only a translation is not a page.
        $this->write('orphan/page.en.txt', "Title: Orphan\n---\n");
        self::assertNull($this->repo()->find('/orphan'));
        self::assertNull($this->repo()->in('en')->find('/orphan'));
    }
}

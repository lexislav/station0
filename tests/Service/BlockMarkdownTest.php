<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Station0\Service\BlockRegistry;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\MediaService;
use Station0\Service\Page;
use Station0\Service\PageRenderer;
use Station0\Tests\Support\BlockFixtures;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** The `markdown` filter in block templates and block render errors (issue #27). */
final class BlockMarkdownTest extends TestCase
{
    private BlockFixtures $fx;
    private string $pagesDir;
    private string $cacheDir;
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->fx       = new BlockFixtures();
        $this->pagesDir = $this->fx->templatesPath . '/../' . basename($this->fx->templatesPath) . '-pages';
        $this->cacheDir = $this->pagesDir . '-cache';
        @mkdir($this->pagesDir . '/about', 0775, true);
        file_put_contents($this->pagesDir . '/about/page.txt', "Title: About\n---\n\nBody\n");
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
        foreach ([$this->pagesDir, $this->cacheDir] as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private function render(string $body, bool $debug = false): string
    {
        $twig     = new Twig(new FilesystemLoader($this->fx->templatesPath));
        $registry = new BlockRegistry($this->fx->blocksPath, $twig, new Logger('test', [$this->log]), $debug);
        $env      = new Environment(['html_input' => 'escape']);
        $env->addExtension(new CommonMarkCoreExtension());
        $renderer = new PageRenderer(
            new MarkdownConverter($env),
            $registry,
            new FileCache($this->cacheDir),
            new MediaService(new ContentRepository($this->pagesDir), $this->pagesDir),
        );
        // Same contract as the filter registered in Bootstrap.
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'markdown',
            fn (array $ctx, ?string $md) => $renderer->markdown((string) $md, $ctx['page_path'] ?? null),
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        $page = new Page(slug: 'about', title: 'About', body: $body);
        $page->urlPath  = '/about';
        $page->filePath = $this->pagesDir . '/about/page.txt';
        return $renderer->render($page, 1);
    }

    private function addBlock(string $type, string $template): void
    {
        $this->fx->addBlock($type, "label: X\nfields:\n  body:\n    type: textarea\n");
        file_put_contents($this->fx->blocksPath . '/' . $type . '/template.twig', $template);
    }

    public function testBlockTemplateRendersMarkdownWithPageLocalImages(): void
    {
        $this->addBlock('callout', '<aside>{{ block.body|markdown }}</aside>');
        $html = $this->render(<<<'YAML'
            - type: callout
              body: |
                See [docs](https://example.com) and `code`.

                ![Team](team.jpg)
            YAML);

        self::assertStringContainsString('<a href="https://example.com">docs</a>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringContainsString('<img src="/media/about/team.jpg" alt="Team" />', $html);
        self::assertStringStartsWith('<aside><p>', $html);
    }

    public function testRawHtmlIsEscaped(): void
    {
        $this->addBlock('callout', '{{ block.body|markdown }}');
        $html = $this->render("- type: callout\n  body: '<script>x</script>'\n");
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testBrokenBlockTemplateIsLogged(): void
    {
        $this->addBlock('broken', '{{ block.body|no_such_filter }}');
        $html = $this->render("- type: broken\n  body: x\n");

        self::assertSame('<!-- block:broken render error -->', $html);
        self::assertTrue($this->log->hasErrorThatContains("Block 'broken' render error on /about"));
    }

    public function testDebugShowsTheMessageInTheComment(): void
    {
        $this->addBlock('broken', '{{ block.body|no_such_filter }}');
        $html = $this->render("- type: broken\n  body: x\n", debug: true);

        self::assertStringStartsWith('<!-- block:broken render error: ', $html);
        self::assertStringContainsString('no_such_filter', $html);
        self::assertSame(2, substr_count($html, '--')); // only <!-- and -->
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ContentRepository;
use Station0\Service\MediaService;
use Station0\Service\Page;
use Station0\Service\PageFields;
use Station0\Service\TemplateBlocks;
use Station0\Tests\Support\BlockFixtures;

final class PageFieldsTest extends TestCase
{
    private BlockFixtures $fx;
    private string $pagesDir;

    protected function setUp(): void
    {
        $this->fx       = new BlockFixtures();
        $this->pagesDir = $this->fx->templatesPath . '/content-pages';
        @mkdir($this->pagesDir, 0775, true);

        $this->fx->addTemplate('product', <<<YAML
            fields:
              subtitle:
                type: text
                default: Hello
              summary:
                type: textarea
              price:
                type: number
              featured:
                type: boolean
              hero:
                type: image
              features:
                type: list
                item:
                  photo:
                    type: image
                  title:
                    type: text
            YAML);
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function make(): PageFields
    {
        $repo = new ContentRepository($this->pagesDir);
        return new PageFields(
            new TemplateBlocks($this->fx->templatesPath, $this->fx->registry()),
            new MediaService($repo, $this->pagesDir),
        );
    }

    private function page(array $extra = [], bool $saved = true): Page
    {
        $page = new Page(slug: 'shoe', title: 'Shoe', body: '', template: 'product', extra: $extra);
        $page->urlPath  = '/shop/shoe';
        $page->filePath = $saved ? $this->pagesDir . '/shop/shoe/product.txt' : '';
        return $page;
    }

    public function testNewPageStartsFromSchemaDefaults(): void
    {
        $values = $this->make()->values($this->page(saved: false));

        self::assertSame('Hello', $values['subtitle']);
        self::assertFalse($values['featured']);
        self::assertSame([], $values['features']);
    }

    public function testSavedPageValuesAreTyped(): void
    {
        $values = $this->make()->values($this->page([
            'price'    => '12.5',
            'featured' => 'true',
            'features' => [['title' => 'A', 'photo' => 'a.jpg', 'junk' => 'x']],
        ]));

        self::assertSame('', $values['subtitle'], 'saved page: a missing value stays empty, no default');
        self::assertSame(12.5, $values['price']);
        self::assertTrue($values['featured']);
        self::assertSame([['photo' => 'a.jpg', 'title' => 'A']], $values['features']);
    }

    public function testApplySanitizesOnlyDeclaredSubmittedFields(): void
    {
        $page = $this->page(['legacy' => 'keep me', 'summary' => 'old']);

        $this->make()->apply($page, [
            'subtitle' => "  line\nbreak ",
            'price'    => 'abc',
            'featured' => true,
            'Title'    => 'hijack',
            'features' => [['title' => ' A ', 'photo' => 'a.jpg', 'extra' => 'dropped'], 'not-an-item'],
        ]);

        self::assertSame('line break', $page->extra['subtitle']);
        self::assertSame('', $page->extra['price']);
        self::assertTrue($page->extra['featured']);
        self::assertSame([['photo' => 'a.jpg', 'title' => 'A']], $page->extra['features']);
        self::assertSame('old', $page->extra['summary'], 'not submitted ⇒ untouched');
        self::assertSame('keep me', $page->extra['legacy'], 'undeclared keys ⇒ untouched');
        self::assertArrayNotHasKey('title', $page->extra);
        self::assertSame('Shoe', $page->title);
    }

    public function testCamelCaseFieldNamesSurviveLowerCasedStorage(): void
    {
        $this->fx->addTemplate('camel', "fields:\n  heroTitle:\n    type: text\n");
        $fields = $this->make();
        $page   = $this->page();
        $page->template = 'camel';

        $fields->apply($page, ['heroTitle' => 'Hi']);

        self::assertSame(['herotitle' => 'Hi'], $page->extra);
        self::assertSame(['heroTitle' => 'Hi'], $fields->values($page));
    }

    public function testResolvedTurnsImagesIntoMediaUrls(): void
    {
        $resolved = $this->make()->resolved($this->page([
            'hero'     => 'hero.jpg',
            'features' => [['photo' => 'a.jpg', 'title' => 'A'], ['photo' => 'https://x.test/b.jpg', 'title' => 'B']],
        ]));

        self::assertSame('/media/shop/shoe/hero.jpg', $resolved['hero']);
        self::assertSame('/media/shop/shoe/a.jpg', $resolved['features'][0]['photo']);
        self::assertSame('https://x.test/b.jpg', $resolved['features'][1]['photo']);
    }

    public function testFullSaveAndReloadThroughRepository(): void
    {
        $repo = new ContentRepository($this->pagesDir);
        @mkdir($this->pagesDir . '/shoe', 0775, true);
        $page = new Page(slug: 'shoe', title: 'Shoe', body: '', template: 'product');
        $fields = $this->make();
        $fields->apply($page, [
            'summary'  => "Line 1\nLine 2",
            'price'    => 99,
            'featured' => false,
            'features' => [['title' => 'Grip', 'photo' => 'g.jpg']],
        ]);
        $repo->save($page, $this->pagesDir . '/shoe/product.txt');

        $loaded = (new ContentRepository($this->pagesDir))->find('/shoe');
        $values = $fields->values($loaded);

        self::assertSame("Line 1\nLine 2", $values['summary']);
        self::assertSame(99, $values['price']);
        self::assertFalse($values['featured']);
        self::assertSame([['photo' => 'g.jpg', 'title' => 'Grip']], $values['features']);
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\TemplateBlocks;
use Station0\Tests\Support\BlockFixtures;

final class TemplateBlocksTest extends TestCase
{
    private BlockFixtures $fx;

    protected function setUp(): void
    {
        $this->fx = new BlockFixtures();
        // Three known block types. available() returns them sorted: gallery, quote, text.
        $this->fx->addBlock('text', "fields:\n  body:\n    type: textarea\n    label: Text\n");
        $this->fx->addBlock('gallery', "label: Gallery\nfields:\n  columns:\n    type: number\n    default: 3\n");
        $this->fx->addBlock('quote', "label: Quote\nfields:\n  cite:\n    type: text\n");
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function make(): TemplateBlocks
    {
        return new TemplateBlocks($this->fx->templatesPath, $this->fx->registry());
    }

    // ─── Allow-list ───

    public function testAllowListRestrictsToDeclaredTypesInOrder(): void
    {
        $this->fx->addTemplate('gallery-page', "allowedBlocks:\n  - gallery\n  - text\n");

        // Declared order is preserved (gallery before text), not the globbed order.
        self::assertSame(['gallery', 'text'], $this->make()->allowedBlocks('gallery-page'));
    }

    public function testNoManifestMeansUnrestricted(): void
    {
        $this->fx->addTemplate('page'); // no manifest

        self::assertSame([], $this->make()->allowedBlocks('page'));
    }

    public function testUnknownBlockNamesAreDroppedFromAllowList(): void
    {
        $this->fx->addTemplate('mixed', "allowedBlocks:\n  - text\n  - nope\n  - gallery\n");

        self::assertSame(['text', 'gallery'], $this->make()->allowedBlocks('mixed'));
    }

    public function testAllowListThatFiltersEmptyFallsBackToUnrestricted(): void
    {
        $this->fx->addTemplate('broken', "allowedBlocks:\n  - nope\n  - alsonope\n");

        // Nothing survived ⇒ [] so the caller shows the full palette, never empty.
        self::assertSame([], $this->make()->allowedBlocks('broken'));
    }

    public function testDuplicatesInAllowListAreCollapsed(): void
    {
        $this->fx->addTemplate('dupes', "allowedBlocks:\n  - text\n  - text\n  - gallery\n");

        self::assertSame(['text', 'gallery'], $this->make()->allowedBlocks('dupes'));
    }

    // ─── Pre-seeding ───

    public function testDefaultBlocksReturnedInOrder(): void
    {
        $this->fx->addTemplate(
            'gallery-page',
            "allowedBlocks:\n  - text\n  - gallery\ndefaultBlocks:\n  - gallery\n  - text\n",
        );

        self::assertSame(['gallery', 'text'], $this->make()->defaultBlocks('gallery-page'));
    }

    public function testNoDefaultBlocksDeclaredMeansEmpty(): void
    {
        $this->fx->addTemplate('page', "allowedBlocks:\n  - text\n");

        self::assertSame([], $this->make()->defaultBlocks('page'));
    }

    public function testDefaultBlockNotInAllowListIsDropped(): void
    {
        $this->fx->addTemplate(
            'restricted',
            "allowedBlocks:\n  - text\ndefaultBlocks:\n  - text\n  - gallery\n",
        );

        // gallery is not allowed here ⇒ dropped; text survives.
        self::assertSame(['text'], $this->make()->defaultBlocks('restricted'));
    }

    public function testUnknownDefaultBlockIsDropped(): void
    {
        $this->fx->addTemplate('page', "defaultBlocks:\n  - gallery\n  - phantom\n");

        // No allow-list ⇒ any known block may be seeded; unknown ones are ignored.
        self::assertSame(['gallery'], $this->make()->defaultBlocks('page'));
    }

    public function testManifestsAreResolvedPerTemplateIndependently(): void
    {
        $this->fx->addTemplate('a', "allowedBlocks:\n  - text\n");
        $this->fx->addTemplate('b', "allowedBlocks:\n  - gallery\n");

        $tb = $this->make();
        self::assertSame(['text'], $tb->allowedBlocks('a'));
        self::assertSame(['gallery'], $tb->allowedBlocks('b'));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Tests\Support\BlockFixtures;

final class BlockRegistryDefaultsTest extends TestCase
{
    private BlockFixtures $fx;

    protected function setUp(): void
    {
        $this->fx = new BlockFixtures();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    public function testTextDefaultsMatchHistoricEmptyBlock(): void
    {
        $this->fx->addBlock('text', "fields:\n  body:\n    type: textarea\n    label: Text\n");

        self::assertSame(
            ['type' => 'text', 'body' => ''],
            $this->fx->registry()->defaults('text'),
        );
    }

    public function testSchemaDefaultsArePopulatedPerType(): void
    {
        $this->fx->addBlock(
            'gallery',
            "label: Gallery\n"
            . "fields:\n"
            . "  columns:\n    type: number\n    default: 3\n"
            . "  heading:\n    type: text\n"
            . "  framed:\n    type: boolean\n    default: true\n"
            . "  size:\n    type: select\n    options: [s, m, l]\n"
            . "  images:\n    type: list\n    item:\n      src:\n        type: image\n",
        );

        self::assertSame(
            [
                'type'    => 'gallery',
                'columns' => 3,       // explicit default
                'heading' => '',      // scalar with no default
                'framed'  => true,    // boolean default
                'size'    => 's',     // select falls back to first option
                'images'  => [],      // list starts empty
            ],
            $this->fx->registry()->defaults('gallery'),
        );
    }

    public function testUnknownTypeYieldsBareBlock(): void
    {
        self::assertSame(['type' => 'mystery'], $this->fx->registry()->defaults('mystery'));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionRepository;
use Station0\Service\FieldOptions;

final class FieldOptionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-col-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $meta */
    private function addItem(string $collection, string $slug, array $meta): void
    {
        $path = $this->dir . '/' . $collection . '/' . $slug;
        @mkdir($path, 0775, true);
        $txt = '';
        foreach ($meta as $k => $v) {
            $txt .= $k . ': ' . $v . "\n";
        }
        file_put_contents($path . '/item.txt', $txt . "---\n");
    }

    private function options(): FieldOptions
    {
        return new FieldOptions(new CollectionRepository($this->dir));
    }

    /** @return list<string> */
    private static function values(array $options): array
    {
        return array_column($options, 'value');
    }

    // ─── Static options ───

    public function testStaticListKeepsHistoricShape(): void
    {
        self::assertSame(
            [
                ['value' => 's', 'label' => 's', 'group' => ''],
                ['value' => '3', 'label' => '3', 'group' => ''],
            ],
            FieldOptions::normalizeStatic(['s', 3]),
        );
    }

    public function testStaticMapAndExplicitEntries(): void
    {
        self::assertSame(
            [['value' => 's', 'label' => 'Small', 'group' => '']],
            FieldOptions::normalizeStatic(['s' => 'Small']),
        );
        self::assertSame(
            [['value' => 'm', 'label' => 'Medium', 'group' => 'Size']],
            FieldOptions::normalizeStatic([['value' => 'm', 'label' => 'Medium', 'group' => 'Size'], ['label' => 'no value']]),
        );
    }

    // ─── Collection source ───

    public function testCollectionSourceUsesSlugAsValueAndTitleAsLabel(): void
    {
        $this->addItem('points', 'weir', ['Title' => 'Weir', 'Sort' => '1']);
        $this->addItem('points', 'bridge', ['Title' => 'Bridge', 'Sort' => '2']);
        $this->addItem('points', 'hidden', ['Title' => 'Hidden', 'Published' => 'false']);

        $opts = $this->options()->resolve(['type' => 'select', 'options_from' => 'collection:points']);

        self::assertSame(
            [
                ['value' => 'weir', 'label' => 'Weir', 'group' => ''],
                ['value' => 'bridge', 'label' => 'Bridge', 'group' => ''],
            ],
            $opts,
        );
    }

    public function testSortByNumericFieldDescendingWithDecimalComma(): void
    {
        $this->addItem('points', 'a', ['Title' => 'A', 'Km' => '9,5']);
        $this->addItem('points', 'b', ['Title' => 'B', 'Km' => '318,5']);
        $this->addItem('points', 'c', ['Title' => 'C', 'Km' => '80']);
        $this->addItem('points', 'd', ['Title' => 'D']);

        $opts = $this->options()->resolve([
            'options_from' => 'collection:points',
            'sort_by'      => '-km',
        ]);

        self::assertSame(['b', 'c', 'a', 'd'], self::values($opts));
    }

    public function testGroupByKeepsGroupsTogetherAndLabelTemplate(): void
    {
        $this->addItem('points', 'v1', ['Title' => 'Vyšší Brod', 'River' => 'Vltava', 'Km' => '318']);
        $this->addItem('points', 'l1', ['Title' => 'Suchdol', 'River' => 'Lužnice', 'Km' => '150']);
        $this->addItem('points', 'v2', ['Title' => 'Krumlov', 'River' => 'Vltava', 'Km' => '282']);

        $field = $this->options()->resolveFields([[
            'name'         => 'from',
            'type'         => 'select',
            'options_from' => 'collection:points',
            'group_by'     => 'River',
            'sort_by'      => '-km',
            'option_label' => '{title} (km {km})',
        ]])[0];

        self::assertSame(['v1', 'v2', 'l1'], self::values($field['options']));
        self::assertSame('Vyšší Brod (km 318)', $field['options'][0]['label']);
        self::assertSame(['Vltava', 'Lužnice'], array_column($field['option_groups'], 'label'));
        self::assertSame('—', $field['placeholder'], 'data-source selects get an empty choice');
    }

    public function testResolvesSelectsInsideListItemFields(): void
    {
        $this->addItem('points', 'weir', ['Title' => 'Weir']);

        $fields = $this->options()->resolveFields([[
            'name'        => 'stops',
            'type'        => 'list',
            'item_fields' => [['name' => 'point', 'type' => 'select', 'options_from' => 'collection:points']],
        ]]);

        self::assertSame(['weir'], self::values($fields[0]['item_fields'][0]['options']));
    }

    public function testUnknownSourceOrMissingCollectionYieldsNoOptions(): void
    {
        self::assertSame([], $this->options()->resolve(['options_from' => 'collection:nope']));
        self::assertSame([], $this->options()->resolve(['options_from' => 'pages:/blog']));
        self::assertSame([], (new FieldOptions())->resolve(['options_from' => 'collection:points']));
    }

    // ─── Initial value ───

    public function testInitialValue(): void
    {
        self::assertSame('s', FieldOptions::initialValue(['options' => ['s', 'm']]));
        self::assertSame('m', FieldOptions::initialValue(['options' => ['s', 'm'], 'default' => 'm']));
        self::assertSame('', FieldOptions::initialValue(['options' => ['s'], 'placeholder' => '—']));
        self::assertSame('', FieldOptions::initialValue(['options_from' => 'collection:points']));
    }
}

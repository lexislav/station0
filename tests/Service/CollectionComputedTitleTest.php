<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionItem;
use Station0\Service\CollectionRepository;

final class CollectionComputedTitleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-ctitle-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function collection(string $name, string $titleYaml): CollectionRepository
    {
        @mkdir("{$this->dir}/{$name}", 0775, true);
        file_put_contents("{$this->dir}/{$name}/_collection.yaml", <<<YAML
            label: People
            {$titleYaml}
            fields:
              name: { type: text, label: Name }
              surname: { type: text, label: Surname }
            YAML);
        return new CollectionRepository($this->dir);
    }

    private function newItem(string $collection, string $slug, string $title, array $extra): CollectionItem
    {
        return new CollectionItem(collection: $collection, slug: $slug, title: $title, body: '', extra: $extra);
    }

    // ── Spec ──

    public function testTitleSpecShorthandAndDict(): void
    {
        $repo = $this->collection('a', 'title: "{name} {surname}"');
        self::assertSame(
            ['from' => '{name} {surname}', 'input' => 'readonly', 'fields' => ['name', 'surname']],
            $repo->titleSpec('a'),
        );

        $repo = $this->collection('b', "title:\n  from: \"{surname}, {name}\"\n  input: Hidden");
        self::assertSame('hidden', $repo->titleSpec('b')['input']);

        $repo = $this->collection('c', "title:\n  from: \"{name}\"\n  input: sometimes");
        self::assertSame('readonly', $repo->titleSpec('c')['input']);

        $repo = $this->collection('d', '');
        self::assertNull($repo->titleSpec('d'));
        self::assertNull($repo->titleSpec('missing'));
    }

    // ── Save ──

    public function testWithoutPatternTheTitleIsKeptAsBefore(): void
    {
        $repo = $this->collection('people', '');
        $repo->save($this->newItem('people', 'jan', 'Typed', ['name' => 'Jan']));

        self::assertSame('Typed', $repo->find('people', 'jan')->title);
    }

    /** @dataProvider forcedModes */
    public function testReadonlyAndHiddenOverwriteAPostedTitle(string $mode): void
    {
        $repo = $this->collection('people', "title:\n  from: \"{name} {surname}\"\n  input: {$mode}");
        $item = $this->newItem('people', 'jan', 'Something else', ['name' => 'Jan', 'surname' => 'Novák']);
        $repo->save($item);

        self::assertSame('Jan Novák', $item->title);
        self::assertSame('Jan Novák', $repo->find('people', 'jan')->title);

        // Fields change → title follows.
        $item = $repo->find('people', 'jan');
        $item->extra['surname'] = 'Dvořák';
        $repo->save($item);
        self::assertSame('Jan Dvořák', $repo->find('people', 'jan')->title);
    }

    public static function forcedModes(): array
    {
        return ['readonly' => ['readonly'], 'hidden' => ['hidden']];
    }

    public function testEmptyResultFallsBackToTheCurrentTitleOrSlug(): void
    {
        $repo = $this->collection('people', 'title: "{name} {surname}"');
        $repo->save($this->newItem('people', 'a', 'Kept', []));
        $repo->save($this->newItem('people', 'b', '', []));

        self::assertSame('Kept', $repo->find('people', 'a')->title);
        self::assertSame('b', $repo->find('people', 'b')->title);
    }

    public function testSlugAndCollectionPlaceholders(): void
    {
        $repo = $this->collection('people', 'title: "{collection}: {slug}"');
        $repo->save($this->newItem('people', 'jan', '', []));

        self::assertSame('people: jan', $repo->find('people', 'jan')->title);
    }

    // ── Editable: override by comparison ──

    public function testEditableNewItemKeepsATypedTitleAndFillsAnEmptyOne(): void
    {
        $repo = $this->collection('people', "title:\n  from: \"{name} {surname}\"\n  input: editable");
        $repo->save($this->newItem('people', 'typed', 'Dr. Jan', ['name' => 'Jan', 'surname' => 'Novák']));
        $repo->save($this->newItem('people', 'empty', '', ['name' => 'Eva', 'surname' => 'Malá']));

        self::assertSame('Dr. Jan', $repo->find('people', 'typed')->title);
        self::assertSame('Eva Malá', $repo->find('people', 'empty')->title);
    }

    public function testEditableTitleInSyncFollowsTheFields(): void
    {
        $repo = $this->collection('people', "title:\n  from: \"{name} {surname}\"\n  input: editable");
        $repo->save($this->newItem('people', 'jan', '', ['name' => 'Jan', 'surname' => 'Novak']));

        // A task fixes the surname and saves without touching the title.
        $item = $repo->find('people', 'jan');
        self::assertTrue($repo->titleInSync($item));
        $item->extra['surname'] = 'Novák';
        $repo->save($item);

        self::assertSame('Jan Novák', $repo->find('people', 'jan')->title);
    }

    public function testEditableOverrideSurvivesFieldChangesUntilCleared(): void
    {
        $repo = $this->collection('people', "title:\n  from: \"{name} {surname}\"\n  input: editable");
        $repo->save($this->newItem('people', 'jan', '', ['name' => 'Jan', 'surname' => 'Novák']));

        // The editor types their own title.
        $item = $repo->find('people', 'jan');
        $item->title = 'Honza';
        $repo->save($item);
        $item = $repo->find('people', 'jan');
        self::assertFalse($repo->titleInSync($item));

        // Later field edits keep the override.
        $item->extra['surname'] = 'Dvořák';
        $repo->save($item);
        self::assertSame('Honza', $repo->find('people', 'jan')->title);

        // Clearing the title (or the "use built title" button) goes back to following.
        $item = $repo->find('people', 'jan');
        $item->title = '';
        $repo->save($item);
        $item = $repo->find('people', 'jan');
        self::assertSame('Jan Dvořák', $item->title);
        self::assertTrue($repo->titleInSync($item));
    }

    public function testEditableTitleEqualToTheNewResultIsInSync(): void
    {
        $repo = $this->collection('people', "title:\n  from: \"{name} {surname}\"\n  input: editable");
        $repo->save($this->newItem('people', 'jan', 'Honza', ['name' => 'Jan', 'surname' => 'Novák']));

        // The form's reset button posts the built title: it is kept and in sync from now on.
        $item = $repo->find('people', 'jan');
        $item->title = 'Jan Novák';
        $repo->save($item);
        $item = $repo->find('people', 'jan');
        $item->extra['name'] = 'Petr';
        $repo->save($item);

        self::assertSame('Petr Novák', $repo->find('people', 'jan')->title);
    }
}

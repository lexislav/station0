<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\TaskHooks;
use Station0\Service\TaskLauncher;
use Station0\Service\TaskRegistry;

final class TaskHooksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-hooks-' . bin2hex(random_bytes(6));
        foreach (['tasks', 'state', 'cache', 'pages', 'collections'] as $d) {
            @mkdir($this->dir . '/' . $d, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function task(string $name, string $php): void
    {
        file_put_contents($this->dir . '/tasks/' . $name . '.php', "<?php\n" . $php);
    }

    /** @return array{TaskRegistry, TaskLauncher, TaskHooks} */
    private function make(): array
    {
        $registry = new TaskRegistry(
            $this->dir . '/tasks',
            $this->dir . '/state',
            ['paths' => []],
            new ContentRepository($this->dir . '/pages'),
            new CollectionRepository($this->dir . '/collections'),
            new FileCache($this->dir . '/cache'),
        );
        $launcher = new TaskLauncher($registry, dirname(__DIR__, 2), $this->dir, ['runner' => 'inline']);
        return [$registry, $launcher, new TaskHooks($registry, $launcher, fn () => 'ed@x.cz')];
    }

    public function testPatternMatching(): void
    {
        self::assertTrue(TaskHooks::matches('page.saved', 'page.saved'));
        self::assertTrue(TaskHooks::matches('page.*', 'page.deleted'));
        self::assertTrue(TaskHooks::matches('*', 'collection.item.saved'));
        self::assertFalse(TaskHooks::matches('page.saved', 'page.deleted'));

        self::assertTrue(TaskHooks::matches('page.saved:/blog', 'page.saved', ['path' => '/blog']));
        self::assertTrue(TaskHooks::matches('page.saved:blog/', 'page.saved', ['path' => '/blog/post']));
        self::assertFalse(TaskHooks::matches('page.saved:/blog', 'page.saved', ['path' => '/blogger']));
        self::assertTrue(TaskHooks::matches('page.moved:/blog', 'page.moved', ['path' => '/news/x', 'previous_path' => '/blog/x']));
        self::assertTrue(TaskHooks::matches('page.saved:/', 'page.saved', ['path' => '/about']));

        self::assertTrue(TaskHooks::matches('collection.item.*:products', 'collection.item.deleted', ['collection' => 'products']));
        self::assertTrue(TaskHooks::matches('collection.item.saved:prod*', 'collection.item.saved', ['collection' => 'products']));
        self::assertFalse(TaskHooks::matches('collection.item.saved:banners', 'collection.item.saved', ['collection' => 'products']));
    }

    public function testDispatchRunsSubscribedTasksWithEventAndDefaults(): void
    {
        $this->task('export', <<<'PHP'
            return [
                'on'     => ['collection.item.*:products'],
                'params' => ['format' => ['type' => 'select', 'options' => ['json', 'csv']]],
                'run'    => function (Station0\Service\TaskContext $t) {
                    $t->info($t->event('event') . ' ' . $t->event('slug') . ' ' . $t->param('format') . ' ' . $t->source);
                },
            ];
            PHP);
        $this->task('other', "return ['on' => ['page.saved'], 'run' => fn () => 0];");
        $this->task('needs-input', "return ['on' => ['collection.item.saved'], 'params' => ['file' => ['type' => 'file', 'required' => true]], 'run' => fn () => 0];");
        [$registry, , $hooks] = $this->make();

        $ids = $hooks->dispatch('collection.item.saved', ['collection' => 'products', 'slug' => 'chair']);

        self::assertCount(1, $ids); // `other` does not listen, `needs-input` lacks a value
        $run = $registry->info($ids[0]);
        self::assertSame('done', $run['status']);
        self::assertSame('hook', $run['source']);
        self::assertSame('ed@x.cz', $run['user']);
        self::assertSame('collection.item.saved', $run['event']['event']);
        self::assertSame('collection.item.saved chair json hook', $run['output'][0]['text']);
    }

    public function testFailingHookDoesNotThrow(): void
    {
        $this->task('boom', "return ['on' => ['page.deleted'], 'run' => function () { throw new RuntimeException('x'); }];");
        [$registry, , $hooks] = $this->make();

        $ids = $hooks->dispatch('page.deleted', ['path' => '/a']);
        self::assertSame('failed', $registry->info($ids[0])['status']);
    }

    public function testLauncherMovesFileParamsIntoRunAndCleansUp(): void
    {
        $this->task('imp', <<<'PHP'
            return [
                'params' => ['file' => ['type' => 'file']],
                'run'    => function (Station0\Service\TaskContext $t) { $t->info(trim(file_get_contents($t->param('file')))); },
            ];
            PHP);
        [$registry, $launcher] = $this->make();
        $upload = $this->dir . '/upload-abc.csv';
        file_put_contents($upload, "hello\n");

        $id  = $launcher->start($registry->find('imp'), ['file' => $upload], 'admin', 'a@b.c');
        $run = $registry->info($id);

        self::assertSame('done', $run['status']);
        self::assertSame('hello', $run['output'][0]['text']);
        self::assertSame(['file' => 'upload-abc.csv'], $run['params']);
        self::assertFileDoesNotExist($upload);
        self::assertDirectoryDoesNotExist($registry->runs()->filesDir($id));
    }
}

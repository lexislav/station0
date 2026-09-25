<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\CollectionRepository;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\TaskRegistry;

final class TaskRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-tasks-' . bin2hex(random_bytes(6));
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

    /** @param list<string>|null $userRoles */
    private function make(?array $userRoles = null): TaskRegistry
    {
        return new TaskRegistry(
            $this->dir . '/tasks',
            $this->dir . '/state',
            ['paths' => ['projectRoot' => $this->dir]],
            new ContentRepository($this->dir . '/pages'),
            new CollectionRepository($this->dir . '/collections'),
            new FileCache($this->dir . '/cache'),
            null,
            $userRoles === null ? null : fn (string $r) => in_array($r, $userRoles, true),
        );
    }

    public function testLoadsDefinitionsSkipsHelpersAndSortsByLabel(): void
    {
        $this->task('zeta', "return ['label' => 'Alpha', 'run' => fn () => 0];");
        $this->task('import-products', "return ['description' => 'CSV', 'run' => fn () => 0];");
        $this->task('_helpers', "function helper() {}");

        $tasks = $this->make()->all();

        self::assertSame(['zeta', 'import-products'], array_column($tasks, 'name'));
        self::assertSame('Import products', $tasks[1]['label']);
        self::assertSame('CSV', $tasks[1]['description']);
    }

    public function testBrokenDefinitionIsListedWithErrorAndFailsToRun(): void
    {
        $this->task('broken', "return ['label' => 'No run'];");
        $this->task('throws', "throw new RuntimeException('boom');");

        $registry = $this->make();
        self::assertStringContainsString("callable 'run'", $registry->find('broken')['error']);
        self::assertStringContainsString('boom', $registry->find('throws')['error']);

        $result = $registry->run($registry->find('broken'), []);
        self::assertFalse($result['ok']);
    }

    public function testRolesDefaultToAdminOnly(): void
    {
        $this->task('admin-only', "return ['run' => fn () => 0];");
        $this->task('editors', "return ['roles' => ['editor'], 'run' => fn () => 0];");

        self::assertSame(['editors'], array_column($this->make(['editor'])->visible(), 'name'));
        self::assertCount(2, $this->make(['admin'])->visible());
        self::assertCount(2, $this->make()->visible()); // CLI: no access control
    }

    public function testResolveParamsTypesDefaultsAndErrors(): void
    {
        $file = $this->dir . '/data.csv';
        file_put_contents($file, "a,b\n");
        $this->task('p', <<<'PHP'
            return [
                'params' => [
                    'limit'   => ['type' => 'number', 'default' => 10, 'max' => 100],
                    'dry_run' => ['type' => 'boolean'],
                    'mode'    => ['type' => 'select', 'options' => ['append', 'replace']],
                    'note'    => ['type' => 'text', 'required' => true],
                    'file'    => ['type' => 'file'],
                ],
                'run' => fn () => 0,
            ];
            PHP);
        $registry = $this->make();
        $task     = $registry->find('p');

        $ok = $registry->resolveParams($task, ['limit' => '2,5', 'dry_run' => true, 'note' => ' hi ', 'file' => $file]);
        self::assertSame([], $ok['errors']);
        self::assertSame(['limit' => 2.5, 'dry_run' => true, 'mode' => 'append', 'note' => 'hi', 'file' => $file], $ok['values']);

        $defaults = $registry->resolveParams($task, ['note' => 'x', 'dry_run' => '0']);
        self::assertSame(10, $defaults['values']['limit']);
        self::assertFalse($defaults['values']['dry_run']);
        self::assertNull($defaults['values']['file']);

        $bad = $registry->resolveParams($task, ['limit' => '500', 'mode' => 'drop', 'file' => $this->dir . '/nope']);
        self::assertSame(['limit' => 'invalid', 'mode' => 'option', 'note' => 'required', 'file' => 'file'], $bad['errors']);
    }

    public function testRunCapturesOutputRecordsLastRunAndFlushesCache(): void
    {
        $this->task('hello', <<<'PHP'
            return ['run' => function (Station0\Service\TaskContext $task) {
                $task->info('start ' . $task->param('who'));
                echo "echoed\npartial";
                $task->warn('careful');
            }];
            PHP);
        $cache = new FileCache($this->dir . '/cache');
        $cache->set('k', 'v');

        $registry = $this->make();
        $live     = [];
        $result   = $registry->run($registry->find('hello'), ['who' => 'me'], 'admin', 'a@b.c',
            function (string $level, string $text) use (&$live) { $live[] = $text; });

        self::assertTrue($result['ok']);
        self::assertSame(['start me', 'echoed', 'careful', 'partial'], array_column($result['output'], 'text'));
        self::assertSame('warn', $result['output'][2]['level']);
        self::assertSame(array_column($result['output'], 'text'), $live);
        self::assertNull($cache->get('k'));

        $last = $registry->lastRun('hello');
        self::assertSame('done', $last['status']);
        self::assertSame(0, $last['exit']);
        self::assertSame('a@b.c', $last['user']);
        self::assertSame(['who' => 'me'], $last['params']);
        self::assertSame(array_column($result['output'], 'text'), array_column($last['output'], 'text'));
        self::assertStringContainsString('hello done exit=0', (string) file_get_contents($this->dir . '/state/tasks.log'));
    }

    public function testExitCodesAndExceptions(): void
    {
        $this->task('fails', "return ['run' => fn () => 3];");
        $this->task('false', "return ['run' => fn () => false];");
        $this->task('throws', "return ['flush_cache' => true, 'run' => function () { echo 'before'; throw new LogicException('bad'); }];");
        $cache = new FileCache($this->dir . '/cache');
        $cache->set('k', 'v');

        $registry = $this->make();
        self::assertSame(3, $registry->run($registry->find('fails'), [])['exit']);
        self::assertSame(1, $registry->run($registry->find('false'), [])['exit']);

        $thrown = $registry->run($registry->find('throws'), []);
        self::assertSame(1, $thrown['exit']);
        self::assertSame('failed', $thrown['status']);
        self::assertSame('before', $thrown['output'][0]['text']);
        self::assertStringContainsString('LogicException: bad', $thrown['output'][1]['text']);
        self::assertSame('v', $cache->get('k')); // no flush after a failure
    }

    public function testConcurrentRunIsRejected(): void
    {
        $this->task('slow', "return ['run' => fn () => 0];");
        $registry = $this->make();

        $lock = fopen($this->dir . '/state/slow.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            self::assertTrue($registry->isRunning('slow'));
            $result = $registry->run($registry->find('slow'), []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertTrue($result['locked']);
        self::assertFalse($result['ok']);
        self::assertSame('skipped', $result['status']);
        self::assertFalse($registry->isRunning('slow'));
    }

    public function testDeadRunIsReportedInterrupted(): void
    {
        $this->task('t', "return ['run' => fn () => 0];");
        $registry = $this->make();
        $id = $registry->runs()->create('t', 'admin', null);
        $registry->runs()->update($id, ['status' => 'running', 'startedAt' => date('c')]);

        $info = $registry->info($id);
        self::assertSame('interrupted', $info['status']);
        self::assertTrue($info['finished']);
    }

    public function testQueuedRunThatNeverStartedFails(): void
    {
        $this->task('t', "return ['run' => fn () => 0];");
        $registry = $this->make();
        $id = $registry->runs()->create('t', 'admin', null);
        $registry->runs()->update($id, ['createdAt' => date('c', time() - 120)]);
        file_put_contents($registry->runs()->errPath($id), "php: not found\n");

        $info = $registry->info($id);
        self::assertSame('failed', $info['status']);
        self::assertSame('php: not found', end($info['output'])['text']);
    }

    public function testOutputOffsetAndPruning(): void
    {
        $this->task('t', "return ['run' => function (\$t) { \$t->info('a'); \$t->info('b'); }];");
        $registry = $this->make();
        $result   = $registry->run($registry->find('t'), []);

        $tail = $registry->info($result['id'], 1);
        self::assertSame(['b'], array_column($tail['output'], 'text'));
        self::assertSame(2, $tail['offset']);

        $runs = $registry->runs();
        for ($i = 0; $i < 3; $i++) {
            $runs->create('t', 'cli', null);
        }
        $runs->prune('t', 2);
        self::assertCount(2, $runs->forTask('t'));
    }
}

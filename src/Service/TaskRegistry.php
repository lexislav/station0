<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\FieldSchema;

/**
 * Site tasks — the webdesigner's own scripts (imports, syncs, rebuilds…),
 * runnable from the console and from the admin.
 *
 * One PHP file per task in site/tasks/, the task name is the file name
 * (`site/tasks/import-products.php` → `import-products`). Files starting
 * with `_` are ignored (shared helpers). The file returns a definition:
 *
 *   return [
 *       'label'       => 'Import products',      // optional, humanized name otherwise
 *       'description' => 'Reads a CSV…',          // optional
 *       'roles'       => ['editor'],              // who may run it in the admin; admins
 *                                                 // always may; omitted = admins only
 *       'confirm'     => 'Really overwrite?',     // optional JS confirm before the run
 *       'params'      => [                        // optional, same dict form as block fields
 *           'file'    => ['type' => 'file', 'label' => 'CSV', 'required' => true],
 *           'dry_run' => ['type' => 'boolean', 'label' => 'Dry run'],
 *       ],
 *       'flush_cache' => true,                    // flush the content cache after a
 *                                                 // successful run (default true)
 *       'timeout'     => 300,                     // max_execution_time, 0 = unlimited (default)
 *       'background'  => true,                    // admin / hook runs in the background (default true)
 *       'on'          => ['page.saved:/blog'],    // hooks — run on these events (see TaskHooks)
 *       'run'         => function (TaskContext $task): int { … return 0; },
 *   ];
 *
 * Param types: text, textarea, number, boolean, select (static `options` or
 * `options_from`, see FieldOptions), file (the value is a local file path —
 * an uploaded temp file in the admin, a path argument in the console).
 *
 * A run holds an exclusive lock per task, so the same task never runs twice
 * at once (a second run ends as `skipped`). Every run gets a record in
 * <logs>/tasks/runs/ (see TaskRuns) with its output written live, and a line
 * in <logs>/tasks/tasks.log.
 */
final class TaskRegistry
{
    public const PARAM_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'file'];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
    /** A queued run that did not start within this many seconds failed to launch. */
    private const START_GRACE = 30;

    /** @var array<string, array>|null */
    private ?array $tasks = null;

    private readonly TaskRuns $runs;

    /**
     * @param \Closure(string): bool|null $hasRole role-name check for the current user;
     *                                           null = no access control (CLI, tests)
     */
    public function __construct(
        private readonly string $tasksDir,
        private readonly string $stateDir,
        private readonly array $config,
        private readonly ContentRepository $pages,
        private readonly CollectionRepository $collections,
        private readonly FileCache $cache,
        private readonly ?FieldOptions $options = null,
        private readonly ?\Closure $hasRole = null,
    ) {
        $this->runs = new TaskRuns(rtrim($this->stateDir, '/') . '/runs');
    }

    public function runs(): TaskRuns
    {
        return $this->runs;
    }

    /**
     * Every task, sorted by label. A file that fails to load or returns an
     * invalid definition is listed with an `error` and cannot be run.
     *
     * @return list<array>
     */
    public function all(): array
    {
        return array_values($this->tasks ??= $this->load());
    }

    /** Tasks the current user may run. */
    public function visible(): array
    {
        return array_values(array_filter($this->all(), fn (array $t) => $this->canRun($t)));
    }

    public function find(string $name): ?array
    {
        $this->tasks ??= $this->load();
        return $this->tasks[$name] ?? null;
    }

    public function canRun(array $task): bool
    {
        if ($this->hasRole === null || ($this->hasRole)('admin')) {
            return true;
        }
        foreach ($task['roles'] as $role) {
            if (($this->hasRole)($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Param fields for a form, select options resolved.
     *
     * @return list<array<string, mixed>>
     */
    public function formParams(array $task): array
    {
        return $this->withOptions($task['params']);
    }

    /**
     * Validate and type raw input (form POST / console `--name=value`).
     * Missing values fall back to the param `default`.
     *
     * Error codes: `required`, `invalid` (not a number / out of min–max),
     * `option` (not one of the select options), `file` (no such file).
     *
     * @param  array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function resolveParams(array $task, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($this->withOptions($task['params']) as $param) {
            $name = $param['name'];
            $raw  = $input[$name] ?? null;
            $raw  = is_string($raw) ? trim($raw) : $raw;
            $has  = $raw !== null && $raw !== '';

            switch ($param['type']) {
                case 'boolean':
                    $values[$name] = $has
                        ? (is_bool($raw) ? $raw : (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN))
                        : (bool) ($param['default'] ?? false);
                    continue 2;

                case 'number':
                    if (!$has) {
                        $values[$name] = isset($param['default']) && is_numeric($param['default'])
                            ? $param['default'] + 0
                            : null;
                        break;
                    }
                    $num = is_string($raw) ? str_replace(',', '.', $raw) : $raw;
                    if (!is_numeric($num)) {
                        $errors[$name] = 'invalid';
                        continue 2;
                    }
                    $num = $num + 0;
                    if ((isset($param['min']) && is_numeric($param['min']) && $num < $param['min'])
                        || (isset($param['max']) && is_numeric($param['max']) && $num > $param['max'])) {
                        $errors[$name] = 'invalid';
                        continue 2;
                    }
                    $values[$name] = $num;
                    break;

                case 'select':
                    $value = $has ? (string) $raw : FieldOptions::initialValue($param);
                    if ($value !== '' && !in_array($value, array_column($param['options'], 'value'), true)) {
                        $errors[$name] = 'option';
                        continue 2;
                    }
                    $values[$name] = $value;
                    break;

                case 'file':
                    if ($has && (!is_string($raw) || !is_file($raw))) {
                        $errors[$name] = 'file';
                        continue 2;
                    }
                    $values[$name] = $has ? $raw : null;
                    break;

                default: // text, textarea
                    $values[$name] = $has ? (string) $raw : (string) ($param['default'] ?? '');
            }

            if (!empty($param['required']) && ($values[$name] === null || $values[$name] === '')) {
                $errors[$name] = 'required';
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Run a task with already resolved param values, in this process.
     * Pass $runId to execute a run record created earlier (background runs);
     * otherwise a new record is created.
     *
     * @param  \Closure(string, string): void|null $onLine live output sink (level, text)
     * @return array run record (see TaskRuns) + ok, locked, output
     */
    public function run(
        array $task,
        array $values,
        string $source = 'cli',
        ?string $user = null,
        ?\Closure $onLine = null,
        array $event = [],
        ?string $runId = null,
    ): array {
        $id = $runId ?? $this->runs->create($task['name'], $source, $user, $this->loggableParams($task, $values), $event);

        $ctx = new TaskContext(
            $task['name'], $values, $this->config, $this->pages, $this->collections, $this->cache,
            $source, $user,
            function (string $level, string $text) use ($id, $onLine): void {
                $this->runs->append($id, $level, $text);
                if ($onLine !== null) {
                    $onLine($level, $text);
                }
            },
            $event,
            $id,
        );
        $started = microtime(true);
        $this->runs->update($id, ['startedAt' => date('c')]);

        if ($task['error'] !== null) {
            $ctx->error($task['error']);
            return $this->finish($id, 'failed', 1, $started);
        }

        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0775, true);
        }
        $lock = @fopen($this->lockPath($task['name']), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $ctx->warn('Task is already running — skipped.');
            return $this->finish($id, 'skipped', 1, $started, true);
        }
        $this->runs->update($id, ['status' => 'running', 'pid' => getmypid()]);

        @set_time_limit($task['timeout']);

        // Capture plain `echo` output as lines, in order with $task->info().
        $pending = '';
        ob_start(function (string $buffer) use ($ctx, &$pending): string {
            $pending .= $buffer;
            while (($pos = strpos($pending, "\n")) !== false) {
                $ctx->line(substr($pending, 0, $pos));
                $pending = substr($pending, $pos + 1);
            }
            return '';
        }, 1);
        $level = ob_get_level();

        $failure = null;
        try {
            $return = ($task['run'])($ctx);
            $exit = match (true) {
                $return === false => 1,
                is_int($return)   => $return,
                default           => 0,
            };
        } catch (\Throwable $e) {
            $exit    = 1;
            $failure = $e;
        } finally {
            // Close buffers the task left open, then our own.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            ob_end_flush();
            if ($pending !== '') {
                $ctx->line($pending);
            }
        }

        if ($failure !== null) {
            $ctx->error(get_class($failure) . ': ' . $failure->getMessage()
                . ' (' . basename($failure->getFile()) . ':' . $failure->getLine() . ')');
        }
        if ($exit === 0 && $task['flushCache']) {
            $this->cache->flush();
        }

        // Record the outcome before releasing the lock: a `running` record
        // with a free lock means the process died (see info()).
        $result = $this->finish($id, $exit === 0 ? 'done' : 'failed', $exit, $started);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }

    /** True while some process holds the task's run lock. */
    public function isRunning(string $name): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name) || !is_file($this->lockPath($name))) {
            return false;
        }
        $lock = @fopen($this->lockPath($name), 'c');
        if ($lock === false) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$free;
    }

    /**
     * A run record with its output and a reconciled status: a `running` run
     * whose lock is free died (`interrupted`); a `queued` run that never
     * started within START_GRACE seconds failed to launch (worker stderr is
     * added to its output).
     */
    public function info(string $id, int $offset = 0): ?array
    {
        $meta = $this->runs->get($id);
        if ($meta === null) {
            return null;
        }

        $changes = [];
        if ($meta['status'] === 'running' && !$this->isRunning($meta['name'])) {
            $changes = ['status' => 'interrupted', 'exit' => 1];
        } elseif ($meta['status'] === 'queued' && $meta['startedAt'] === null
            && time() - (int) strtotime((string) $meta['createdAt']) > self::START_GRACE) {
            $this->runs->append($id, 'error', 'The background worker did not start.');
            foreach (preg_split('/\R/', $this->runs->stderr($id)) ?: [] as $line) {
                if ($line !== '') {
                    $this->runs->append($id, 'error', $line);
                }
            }
            $changes = ['status' => 'failed', 'exit' => 1];
        }
        if ($changes !== []) {
            $changes['finishedAt'] = date('c');
            $this->runs->update($id, $changes);
            $meta = array_merge($meta, $changes);
        }

        $out = $this->runs->output($id, $offset);
        return $meta + [
            'ok'       => $meta['status'] === 'done',
            'finished' => !in_array($meta['status'], ['queued', 'running'], true),
            'output'   => $out['lines'],
            'offset'   => $out['offset'],
        ];
    }

    /** The newest run of a task (possibly still running), or null. */
    public function lastRun(string $name): ?array
    {
        $latest = $this->runs->forTask($name, 1)[0] ?? null;
        return $latest !== null ? $this->info($latest['id']) : null;
    }

    // ─── Internals ───

    private function finish(string $id, string $status, int $exit, float $started, bool $locked = false): array
    {
        $this->runs->update($id, [
            'status'     => $status,
            'exit'       => $exit,
            'finishedAt' => date('c'),
            'duration'   => round(microtime(true) - $started, 3),
        ]);
        $meta = (array) $this->runs->get($id);

        @file_put_contents(
            $this->stateDir . '/tasks.log',
            sprintf(
                "[%s] %s %s exit=%d %.3fs via %s%s%s\n",
                $meta['startedAt'] ?? date('c'), $meta['name'] ?? '?', $status, $exit, $meta['duration'] ?? 0,
                $meta['source'] ?? '?',
                !empty($meta['event']['event']) ? ' (' . $meta['event']['event'] . ')' : '',
                ($meta['user'] ?? null) !== null ? ' by ' . $meta['user'] : '',
            ),
            FILE_APPEND | LOCK_EX,
        );

        return $meta + [
            'ok'       => $status === 'done',
            'locked'   => $locked,
            'finished' => true,
            'output'   => $this->runs->output($id)['lines'],
        ];
    }

    /** Param values as shown in the run history — file params by base name. */
    public function loggableParams(array $task, array $values): array
    {
        foreach ($task['params'] as $param) {
            if ($param['type'] === 'file' && isset($values[$param['name']]) && is_string($values[$param['name']])) {
                $values[$param['name']] = basename($values[$param['name']]);
            }
        }
        return $values;
    }

    private function lockPath(string $name): string
    {
        return rtrim($this->stateDir, '/') . '/' . $name . '.lock';
    }

    /** @return array<string, array> keyed by name, sorted by label */
    private function load(): array
    {
        $tasks = [];
        foreach (glob(rtrim($this->tasksDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (!preg_match(self::NAME_PATTERN, $name)) {
                continue; // `_helpers.php` and friends
            }
            $tasks[$name] = $this->define($name, $file);
        }
        uasort($tasks, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
        return $tasks;
    }

    private function define(string $name, string $file): array
    {
        $task = [
            'name'        => $name,
            'label'       => ucfirst(str_replace(['-', '_'], ' ', $name)),
            'description' => '',
            'roles'       => [],
            'confirm'     => '',
            'params'      => [],
            'flushCache'  => true,
            'timeout'     => 0,
            'background'  => true,
            'on'          => [],
            'run'         => null,
            'error'       => null,
        ];

        try {
            $def = (static fn (string $__file) => require $__file)($file);
        } catch (\Throwable $e) {
            $task['error'] = 'Cannot load ' . basename($file) . ': ' . $e->getMessage();
            return $task;
        }
        if (!is_array($def) || !isset($def['run']) || !is_callable($def['run'])) {
            $task['error'] = basename($file) . " must return an array with a callable 'run'.";
            return $task;
        }

        $roles = $def['roles'] ?? [];
        $task['label']       = trim((string) ($def['label'] ?? '')) ?: $task['label'];
        $task['description'] = trim((string) ($def['description'] ?? ''));
        $task['roles']       = array_values(array_filter(array_map('strval', (array) $roles), fn ($r) => $r !== ''));
        $task['confirm']     = trim((string) ($def['confirm'] ?? ''));
        $task['params']      = $this->normalizeParams(is_array($def['params'] ?? null) ? $def['params'] : []);
        $task['flushCache']  = (bool) ($def['flush_cache'] ?? true);
        $task['timeout']     = isset($def['timeout']) && is_numeric($def['timeout']) ? max(0, (int) $def['timeout']) : 0;
        $task['background']  = (bool) ($def['background'] ?? true);
        $task['on']          = array_values(array_filter(array_map(
            fn ($e) => trim((string) $e),
            (array) ($def['on'] ?? []),
        ), fn ($e) => $e !== ''));
        $task['run']         = \Closure::fromCallable($def['run']);
        return $task;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeParams(array $raw): array
    {
        $out = [];
        foreach (FieldSchema::normalize($raw) as $param) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $param['name'])) {
                continue;
            }
            $param['type']  = in_array($param['type'] ?? 'text', self::PARAM_TYPES, true) ? ($param['type'] ?? 'text') : 'text';
            $param['label'] = (string) ($param['label'] ?? $param['name']);
            $out[] = $param;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function withOptions(array $params): array
    {
        return ($this->options ?? new FieldOptions())->resolveFields($params);
    }
}

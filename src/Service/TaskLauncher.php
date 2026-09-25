<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Starts site-task runs outside the current request.
 *
 * `start()` creates a queued run record and hands it to a runner:
 *
 *   spawn    a detached `php bin/console task:worker <run-id>` process
 *            (needs exec() and a PHP CLI binary)
 *   fastcgi  the same PHP-FPM worker, after the response is sent
 *            (fastcgi_finish_request)
 *   inline   synchronously, before the response (always works)
 *
 * site/config.php, all optional:
 *
 *   'tasks' => [
 *       'runner' => 'auto',          // auto | spawn | fastcgi | inline
 *       'php'    => '/usr/bin/php',  // PHP CLI for `spawn`; detected otherwise
 *   ],
 *
 * `auto` = spawn when possible, else fastcgi when available, else inline.
 * A task with `'background' => false` always runs inline.
 */
final class TaskLauncher
{
    public const RUNNERS = ['auto', 'spawn', 'fastcgi', 'inline'];

    /**
     * @param array{runner?: string, php?: string} $options  config['tasks']
     */
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly string $station0Root,
        private readonly string $projectRoot,
        private readonly array $options = [],
    ) {}

    /**
     * Queue a run and start it. File params (local paths, e.g. temp uploads)
     * are moved into the run's own directory, which is removed after the run.
     *
     * @return string run id
     */
    public function start(array $task, array $values, string $source, ?string $user = null, array $event = []): string
    {
        $runs = $this->tasks->runs();
        $id   = $runs->create($task['name'], $source, $user, $this->tasks->loggableParams($task, $values), $event);

        foreach ($task['params'] as $param) {
            $path = $values[$param['name']] ?? null;
            if ($param['type'] !== 'file' || !is_string($path) || !is_file($path)) {
                continue;
            }
            $dir = $runs->filesDir($id);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $target = $dir . '/' . $param['name'] . '-' . basename($path);
            if (@rename($path, $target) || (@copy($path, $target) && @unlink($path))) {
                $values[$param['name']] = $target;
            }
        }
        $runs->update($id, ['values' => $values]);

        $runner = $task['background'] ? $this->runner() : 'inline';
        $runs->update($id, ['runner' => $runner]);

        match ($runner) {
            'spawn'   => $this->spawn($id),
            'fastcgi' => $this->defer($id),
            default   => $this->work($id),
        };
        return $id;
    }

    /**
     * Execute a queued run in this process (the `task:worker` command, the
     * fastcgi and inline runners).
     */
    public function work(string $id): ?array
    {
        $runs = $this->tasks->runs();
        $meta = $runs->get($id);
        if ($meta === null || $meta['status'] !== 'queued') {
            return null;
        }
        $task = $this->tasks->find($meta['name']);
        try {
            if ($task === null) {
                $runs->append($id, 'error', "Task '{$meta['name']}' no longer exists.");
                $runs->update($id, ['status' => 'failed', 'exit' => 1, 'finishedAt' => date('c')]);
                return $runs->get($id);
            }
            $values = (array) ($meta['values'] ?? $meta['params'] ?? []);
            return $this->tasks->run(
                $task, $values, (string) $meta['source'], $meta['user'] ?? null, null,
                (array) ($meta['event'] ?? []), $id,
            );
        } finally {
            $runs->removeFiles($id);
            if ($runs->stderr($id) === '') {
                @unlink($runs->errPath($id));
            }
        }
    }

    /** The runner `auto` resolves to here, or the configured one. */
    public function runner(): string
    {
        $wanted = (string) ($this->options['runner'] ?? 'auto');
        $wanted = in_array($wanted, self::RUNNERS, true) ? $wanted : 'auto';
        if ($wanted !== 'auto') {
            return $wanted;
        }
        if ($this->canSpawn()) {
            return 'spawn';
        }
        return function_exists('fastcgi_finish_request') ? 'fastcgi' : 'inline';
    }

    public function canSpawn(): bool
    {
        return DIRECTORY_SEPARATOR === '/' && $this->execEnabled() && $this->phpBinary() !== null;
    }

    /** The PHP CLI binary for `spawn`, or null when none is found. */
    public function phpBinary(): ?string
    {
        if (!empty($this->options['php'])) {
            return (string) $this->options['php'];
        }
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        // Under PHP-FPM, PHP_BINARY is php-fpm itself — look for the CLI next to it.
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ([PHP_BINDIR . '/php', PHP_BINDIR . '/php' . $v, '/usr/bin/php' . $v, '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    // ─── Runners ───

    private function spawn(string $id): void
    {
        // Redirect the whole group: a subshell left holding exec()'s output
        // pipe would make exec() — and the request — wait for the worker.
        $cmd = sprintf(
            '(cd %s && %s %s task:worker %s) > %s 2>&1 < /dev/null &',
            escapeshellarg($this->projectRoot),
            escapeshellarg((string) $this->phpBinary()),
            escapeshellarg($this->station0Root . '/bin/console'),
            escapeshellarg($id),
            escapeshellarg($this->tasks->runs()->errPath($id)),
        );
        exec($cmd);
    }

    private function defer(string $id): void
    {
        register_shutdown_function(function () use ($id): void {
            ignore_user_abort(true);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // don't block the user's next request
            }
            fastcgi_finish_request();
            $this->work($id);
        });
    }

    private function execEnabled(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }
}

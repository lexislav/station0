<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Run records of site tasks, one per run (console, admin, hook), in
 * <logs>/tasks/runs/:
 *
 *   <id>.json     meta — name, status, source, user, event, params, times, exit
 *   <id>.out      output, one JSON line {level, text} per line, appended live
 *   <id>.err      stderr of a background worker (start-up failures)
 *   <id>-files/   uploads handed to a background run, removed after it
 *
 * Status: queued → running → done | failed; `skipped` when the task was
 * already running. The registry reports a dead run as `interrupted`.
 * Only the newest KEEP runs of each task are kept.
 */
final class TaskRuns
{
    public const KEEP = 30;
    private const ID_PATTERN = '/^([a-z0-9][a-z0-9_-]*)--\d{8}-\d{6}-[a-f0-9]{6}$/';

    public function __construct(private readonly string $dir) {}

    public static function isId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id);
    }

    /** Task name encoded in a run id. */
    public static function taskOf(string $id): ?string
    {
        return preg_match(self::ID_PATTERN, $id, $m) ? $m[1] : null;
    }

    public function create(string $name, string $source, ?string $user, array $params = [], array $event = []): string
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $this->prune($name, self::KEEP - 1);

        $id = $name . '--' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $this->write($id, [
            'id'         => $id,
            'name'       => $name,
            'status'     => 'queued',
            'source'     => $source,
            'user'       => $user,
            'event'      => $event,
            'params'     => $params,
            'createdAt'  => date('c'),
            'startedAt'  => null,
            'finishedAt' => null,
            'duration'   => null,
            'exit'       => null,
        ]);
        touch($this->path($id, '.out'));
        return $id;
    }

    public function get(string $id): ?array
    {
        if (!self::isId($id) || !is_file($this->path($id, '.json'))) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path($id, '.json')), true);
        return is_array($data) ? $data : null;
    }

    public function update(string $id, array $changes): void
    {
        $meta = $this->get($id);
        if ($meta !== null) {
            $this->write($id, array_merge($meta, $changes));
        }
    }

    public function append(string $id, string $level, string $text): void
    {
        @file_put_contents(
            $this->path($id, '.out'),
            json_encode(['level' => $level, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Output lines from line $offset on.
     *
     * @return array{lines: list<array{level: string, text: string}>, offset: int}
     */
    public function output(string $id, int $offset = 0): array
    {
        $file = $this->path($id, '.out');
        if (!self::isId($id) || !is_file($file)) {
            return ['lines' => [], 'offset' => $offset];
        }
        $raw   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = [];
        foreach (array_slice($raw, max(0, $offset)) as $json) {
            $line = json_decode($json, true);
            if (is_array($line)) {
                $lines[] = ['level' => (string) ($line['level'] ?? 'info'), 'text' => (string) ($line['text'] ?? '')];
            }
        }
        return ['lines' => $lines, 'offset' => max(0, $offset) + count($lines)];
    }

    /** Worker stderr (empty when none). */
    public function stderr(string $id): string
    {
        $file = $this->path($id, '.err');
        return self::isId($id) && is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** Directory for files handed to a background run (created on demand). */
    public function filesDir(string $id): string
    {
        return $this->path($id, '-files');
    }

    public function removeFiles(string $id): void
    {
        $dir = $this->filesDir($id);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function errPath(string $id): string
    {
        return $this->path($id, '.err');
    }

    /**
     * Newest runs of a task first.
     *
     * @return list<array>
     */
    public function forTask(string $name, int $limit = self::KEEP): array
    {
        $out = [];
        foreach (array_slice($this->ids($name), 0, $limit) as $id) {
            if (($meta = $this->get($id)) !== null) {
                $out[] = $meta;
            }
        }
        return $out;
    }

    /** Delete all but the newest $keep runs of a task. */
    public function prune(string $name, int $keep = self::KEEP): void
    {
        foreach (array_slice($this->ids($name), max(0, $keep)) as $id) {
            foreach (['.json', '.out', '.err'] as $ext) {
                @unlink($this->path($id, $ext));
            }
            $this->removeFiles($id);
        }
    }

    /** @return list<string> newest first */
    private function ids(string $name): array
    {
        $ids = [];
        foreach (glob($this->dir . '/' . $name . '--*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (self::taskOf($id) === $name) {
                $ids[] = $id;
            }
        }
        rsort($ids, SORT_STRING); // ids embed the timestamp
        return $ids;
    }

    private function write(string $id, array $meta): void
    {
        $tmp = $this->path($id, '.json.tmp' . bin2hex(random_bytes(3)));
        file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->path($id, '.json'));
    }

    private function path(string $id, string $suffix): string
    {
        return $this->dir . '/' . $id . $suffix;
    }
}

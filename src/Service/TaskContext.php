<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * What a site task's `run` closure receives — its parameters, an output
 * channel and the content services. The same object is used whether the task
 * runs from `php vendor/bin/console task:run` or from the admin.
 *
 *   'run' => function (TaskContext $task): int {
 *       $rows = array_map('str_getcsv', file($task->param('file')));
 *       foreach ($rows as $row) { … $task->collections()->save($item); }
 *       $task->info(count($rows) . ' rows imported.');
 *       return 0;   // non-zero / false = failure; null / true = success
 *   }
 *
 * Plain `echo` output is captured too (one line per "\n"). A hook run gets
 * the triggering event via event().
 */
final class TaskContext
{
    /** @var list<array{level: string, text: string}> */
    private array $lines = [];

    /**
     * @param array<string, mixed>                  $params  sanitized parameter values
     * @param \Closure(string, string): void|null   $onLine  live output sink (level, text), e.g. CLI echo
     */
    public function __construct(
        public readonly string $name,
        private readonly array $params,
        private readonly array $config,
        private readonly ContentRepository $pages,
        private readonly CollectionRepository $collections,
        private readonly FileCache $cache,
        /** 'cli' | 'admin' | 'hook' */
        public readonly string $source = 'cli',
        /** E-mail of the admin user who started the run; null from the CLI. */
        public readonly ?string $user = null,
        private readonly ?\Closure $onLine = null,
        /** The event that triggered a hook run (['event' => 'page.saved', 'path' => …]); [] otherwise. */
        private readonly array $event = [],
        public readonly ?string $runId = null,
    ) {}

    /**
     * Event payload of a hook run, or [] for a manual run. With a key, one
     * payload value: event('path'), event('collection'), event('event')…
     */
    public function event(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->event : ($this->event[$key] ?? $default);
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function line(string $text): void
    {
        $this->write('info', $text);
    }

    public function info(string $text): void
    {
        $this->write('info', $text);
    }

    public function success(string $text): void
    {
        $this->write('success', $text);
    }

    public function warn(string $text): void
    {
        $this->write('warn', $text);
    }

    public function error(string $text): void
    {
        $this->write('error', $text);
    }

    public function write(string $level, string $text): void
    {
        foreach (explode("\n", rtrim(str_replace("\r\n", "\n", $text), "\n")) as $line) {
            $this->lines[] = ['level' => $level, 'text' => $line];
            if ($this->onLine !== null) {
                ($this->onLine)($level, $line);
            }
        }
    }

    /** @return list<array{level: string, text: string}> */
    public function output(): array
    {
        return $this->lines;
    }

    public function pages(): ContentRepository
    {
        return $this->pages;
    }

    public function collections(): CollectionRepository
    {
        return $this->collections;
    }

    public function cache(): FileCache
    {
        return $this->cache;
    }

    /** The site config array (site/config.php). */
    public function config(): array
    {
        return $this->config;
    }

    /** A configured path, e.g. path('projectRoot'), path('content'), path('logs'). */
    public function path(string $key): string
    {
        return (string) ($this->config['paths'][$key] ?? '');
    }
}

<?php

declare(strict_types=1);

namespace Station0\Service;

use Psr\Log\LoggerInterface;

/**
 * Hooks — site tasks that run on content events. A task subscribes with
 * `on`, a list of `<event>[:<filter>]` patterns:
 *
 *   'on' => ['page.saved:/blog', 'collection.item.*:products'],
 *
 * The event part may use shell wildcards (`page.*`, `*`). The filter is a
 * page path for page events (`/blog` = /blog and everything below it) and
 * a collection name (wildcards allowed) for collection events.
 *
 * Events (fired by the admin after the change is stored) and their payload,
 * available in the task as $task->event():
 *
 *   page.saved               path, title, template, created (bool), previous_path (after a rename)
 *   page.moved               path, previous_path
 *   page.deleted             path, title, template
 *   collection.item.saved    collection, slug, title, created (bool), previous_slug (after a rename)
 *   collection.item.deleted  collection, slug, title
 *
 * Every payload also carries `event` (its name). Hook runs start via
 * TaskLauncher (background unless the task says `'background' => false`),
 * with param defaults; a task with a required param that has no default is
 * not run. A failing hook never breaks the admin action — errors are logged.
 * Content changes made by a task do not fire events.
 */
final class TaskHooks
{
    public const EVENTS = [
        'page.saved',
        'page.moved',
        'page.deleted',
        'collection.item.saved',
        'collection.item.deleted',
    ];

    /**
     * @param \Closure(): ?string|null $user  e-mail of the current user
     */
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly TaskLauncher $launcher,
        private readonly ?\Closure $user = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Start every task subscribed to the event.
     *
     * @return list<string> ids of the started runs
     */
    public function dispatch(string $event, array $payload = []): array
    {
        $payload = ['event' => $event] + $payload;
        $ids     = [];

        try {
            $listeners = $this->listeners($event, $payload);
        } catch (\Throwable $e) {
            $this->logger?->error('Task hooks failed to load: ' . $e->getMessage());
            return [];
        }

        foreach ($listeners as $task) {
            try {
                ['values' => $values, 'errors' => $errors] = $this->tasks->resolveParams($task, []);
                if ($errors !== []) {
                    $this->logger?->warning(sprintf(
                        "Hook task '%s' not run on %s: params without a value (%s).",
                        $task['name'], $event, implode(', ', array_keys($errors)),
                    ));
                    continue;
                }
                $user  = $this->user !== null ? ($this->user)() : null;
                $ids[] = $this->launcher->start($task, $values, 'hook', $user, $payload);
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf("Hook task '%s' on %s failed: %s", $task['name'], $event, $e->getMessage()));
            }
        }
        return $ids;
    }

    /**
     * Valid tasks subscribed to the event.
     *
     * @return list<array>
     */
    public function listeners(string $event, array $payload = []): array
    {
        $payload = ['event' => $event] + $payload;
        return array_values(array_filter(
            $this->tasks->all(),
            function (array $task) use ($event, $payload): bool {
                if ($task['error'] !== null) {
                    return false;
                }
                foreach ($task['on'] as $pattern) {
                    if (self::matches($pattern, $event, $payload)) {
                        return true;
                    }
                }
                return false;
            },
        ));
    }

    public static function matches(string $pattern, string $event, array $payload = []): bool
    {
        [$name, $filter] = array_pad(explode(':', $pattern, 2), 2, null);
        if (!fnmatch(trim((string) $name), $event)) {
            return false;
        }
        $filter = trim((string) $filter);
        if ($filter === '') {
            return true;
        }

        if (str_starts_with($event, 'page.')) {
            $prefix = '/' . trim($filter, '/');
            foreach ([$payload['path'] ?? null, $payload['previous_path'] ?? null] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return true;
                }
            }
            return false;
        }

        if (str_starts_with($event, 'collection.')) {
            return is_string($payload['collection'] ?? null) && fnmatch($filter, $payload['collection']);
        }

        return false;
    }
}

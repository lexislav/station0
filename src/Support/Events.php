<?php

declare(strict_types=1);

namespace Station0\Support;

use Psr\Log\LoggerInterface;

/**
 * Content events — the admin controllers dispatch here after a change is
 * stored; site tasks (TaskHooks) and plugins listen.
 *
 *   $events->on('page.saved:/blog', fn (array $payload, string $event) => …);
 *   $events->dispatch('page.saved', ['path' => '/blog/post', …]);
 *
 * A pattern is `<event>[:<filter>]`; the event part may use shell wildcards
 * (`page.*`, `*`). The filter is a page path prefix for page events (`/blog`
 * = /blog and below, `previous_path` counts too) and a collection name
 * (wildcards allowed) for collection events.
 *
 * Every payload carries `event` (its name). Listeners run in registration
 * order; one that throws is logged and the others still run, so a listener
 * never breaks the admin action that fired the event.
 */
final class Events
{
    /** @var list<array{string, callable}> */
    private array $listeners = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /** @param callable(array $payload, string $event): mixed $listener */
    public function on(string $pattern, callable $listener): void
    {
        $this->listeners[] = [$pattern, $listener];
    }

    /** @return int number of listeners called */
    public function dispatch(string $event, array $payload = []): int
    {
        $payload = ['event' => $event] + $payload;
        $called  = 0;
        foreach ($this->listeners as [$pattern, $listener]) {
            if (!self::matches($pattern, $event, $payload)) {
                continue;
            }
            $called++;
            try {
                $listener($payload, $event);
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf("Listener for '%s' on %s failed: %s", $pattern, $event, $e->getMessage()));
            }
        }
        return $called;
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

<?php

declare(strict_types=1);

namespace Station0\Service;

use PDO;

/**
 * Fixed-window rate limiter with lockout, in the SQLite table `station0_throttle`.
 *
 *   $limit = $limiter->hit('login:' . $ip, max: 5, window: 900);
 *   if (!$limit['allowed']) { … wait $limit['retryAfter'] seconds … }
 *
 * hit() records one event. Within `window` seconds at most `max` events are
 * allowed; the event that reaches `max` locks the key for `lock` seconds
 * (default: the window). To count only failures, check blocked() first and
 * call hit() on a failure.
 */
final class RateLimiter
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS station0_throttle (
            key          TEXT    PRIMARY KEY,
            hits         INTEGER NOT NULL,
            window_start INTEGER NOT NULL,
            locked_until INTEGER NOT NULL DEFAULT 0
        );
        SQL;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(private readonly PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->pdo->exec(self::SCHEMA);
    }

    /**
     * Record one event for $key.
     *
     * @return array{allowed: bool, remaining: int, retryAfter: int}
     */
    public function hit(string $key, int $max, int $window, ?int $lock = null): array
    {
        $max  = max(1, $max);
        $now  = ($this->clock)();
        $lock = $lock ?? $window;
        $row  = $this->row($key);

        if ($row !== null && $row['locked_until'] > $now) {
            return ['allowed' => false, 'remaining' => 0, 'retryAfter' => $row['locked_until'] - $now];
        }
        if ($row === null || $now - $row['window_start'] >= $window || $row['locked_until'] > 0) {
            $row = ['hits' => 0, 'window_start' => $now, 'locked_until' => 0];
        }

        $row['hits']++;
        if ($row['hits'] >= $max) {
            $row['locked_until'] = $now + $lock;
        }
        $this->pdo->prepare(
            'INSERT INTO station0_throttle (key, hits, window_start, locked_until) VALUES (:k, :h, :w, :l)
             ON CONFLICT(key) DO UPDATE SET hits = excluded.hits, window_start = excluded.window_start, locked_until = excluded.locked_until'
        )->execute([':k' => $key, ':h' => $row['hits'], ':w' => $row['window_start'], ':l' => $row['locked_until']]);

        if (random_int(1, 100) === 1) {
            $this->purge($now - 86400);
        }

        return [
            'allowed'    => true,
            'remaining'  => max(0, $max - $row['hits']),
            'retryAfter' => $row['locked_until'] > 0 ? $row['locked_until'] - $now : 0,
        ];
    }

    /** Seconds until $key is unlocked; 0 when it is not locked. */
    public function blocked(string $key): int
    {
        $row = $this->row($key);
        $now = ($this->clock)();
        return $row !== null && $row['locked_until'] > $now ? $row['locked_until'] - $now : 0;
    }

    public function reset(string $key): void
    {
        $this->pdo->prepare('DELETE FROM station0_throttle WHERE key = :k')->execute([':k' => $key]);
    }

    /** Drop rows that are neither locked nor in a window started after $before. */
    private function purge(int $before): void
    {
        $this->pdo->prepare('DELETE FROM station0_throttle WHERE window_start < :b AND locked_until < :now')
            ->execute([':b' => $before, ':now' => ($this->clock)()]);
    }

    /** @return array{hits: int, window_start: int, locked_until: int}|null */
    private function row(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT hits, window_start, locked_until FROM station0_throttle WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? ['hits' => (int) $r['hits'], 'window_start' => (int) $r['window_start'], 'locked_until' => (int) $r['locked_until']] : null;
    }
}

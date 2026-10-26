<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use PDO;

/**
 * The 404 log (`station0_404`) and opt-in rule hit counters
 * (`station0_redirect_hits`). Paths are stored without the query string
 * and truncated, the referrer without its query, so no form data or tokens
 * end up in the log.
 */
final class Log
{
    public const MAX_PATH = 512;

    /**
     * @param list<string> $ignore glob patterns never logged (`/wp-*`, `*.php`)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $ignore = [],
        private readonly int $cap = 5000,
    ) {}

    public static function migrations(): array
    {
        return [
            '001-404-log' => 'CREATE TABLE station0_404 (
                path TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 1,
                first_seen TEXT NOT NULL, last_seen TEXT NOT NULL,
                referrer TEXT, ignored INTEGER NOT NULL DEFAULT 0);
                CREATE INDEX station0_404_last_seen ON station0_404 (last_seen);
                CREATE TABLE station0_redirect_hits (
                rule TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, last_hit TEXT)',
        ];
    }

    public function ignores(string $path): bool
    {
        foreach ($this->ignore as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
        }
        return false;
    }

    /** Count a miss; returns false when the path is ignored. */
    public function record404(string $path, ?string $referrer = null): bool
    {
        $path = mb_strcut(Rules::normalizePath($path), 0, self::MAX_PATH);
        if ($this->ignores($path)) {
            return false;
        }
        $referrer = $referrer !== null && $referrer !== '' ? mb_strcut((string) preg_replace('/[?#].*$/s', '', $referrer), 0, self::MAX_PATH) : null;
        $now = date('c');
        $this->pdo->prepare('INSERT INTO station0_404 (path, hits, first_seen, last_seen, referrer) VALUES (:p, 1, :n, :n, :r)
            ON CONFLICT(path) DO UPDATE SET hits = hits + 1, last_seen = :n, referrer = COALESCE(:r, referrer)')
            ->execute([':p' => $path, ':n' => $now, ':r' => $referrer]);
        // Keep the table bounded; pruning on every 50th write on average is plenty.
        if (random_int(1, 50) === 1) {
            $this->prune();
        }
        return true;
    }

    /** Drop the oldest rows above the cap. */
    public function prune(): int
    {
        return (int) $this->pdo->exec('DELETE FROM station0_404 WHERE path NOT IN (
            SELECT path FROM station0_404 ORDER BY last_seen DESC LIMIT ' . max(1, $this->cap) . ')');
    }

    /** @return list<array{path: string, hits: int, first_seen: string, last_seen: string, referrer: ?string}> */
    public function entries(int $limit = 200, bool $ignored = false): array
    {
        $stmt = $this->pdo->prepare('SELECT path, hits, first_seen, last_seen, referrer FROM station0_404
            WHERE ignored = :i ORDER BY hits DESC, last_seen DESC LIMIT :l');
        $stmt->bindValue(':i', $ignored ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $r) => ['hits' => (int) $r['hits']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Hide a path from the log (it keeps counting). */
    public function ignore(string $path, bool $ignored = true): void
    {
        $this->pdo->prepare('UPDATE station0_404 SET ignored = ? WHERE path = ?')->execute([$ignored ? 1 : 0, $path]);
    }

    public function forget(string $path): void
    {
        $this->pdo->prepare('DELETE FROM station0_404 WHERE path = ?')->execute([$path]);
    }

    public function clear(): int
    {
        return (int) $this->pdo->exec('DELETE FROM station0_404 WHERE ignored = 0');
    }

    public function hit(string $ruleId): void
    {
        $this->pdo->prepare('INSERT INTO station0_redirect_hits (rule, hits, last_hit) VALUES (?, 1, ?)
            ON CONFLICT(rule) DO UPDATE SET hits = hits + 1, last_hit = excluded.last_hit')
            ->execute([$ruleId, date('c')]);
    }

    /** @return array<string, array{hits: int, last_hit: ?string}> rule id ⇒ counter */
    public function hits(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT rule, hits, last_hit FROM station0_redirect_hits')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['rule']] = ['hits' => (int) $r['hits'], 'last_hit' => $r['last_hit']];
        }
        return $out;
    }
}

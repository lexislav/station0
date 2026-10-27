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

    public const SORTS = ['hits' => 'hits', 'path' => 'path', 'first' => 'first_seen', 'last' => 'last_seen'];

    /** @return list<array{path: string, hits: int, first_seen: string, last_seen: string, referrer: ?string}> */
    public function entries(int $limit = 200, bool $ignored = false): array
    {
        return $this->search(['ignored' => $ignored], $limit)['entries'];
    }

    /**
     * Filtered, sorted page of the log (admin 404 view).
     *
     * Filters: `q` (path contains), `ref` (referrer contains; `-` = none),
     * `min` (hits at least), `days` (seen within the last n days),
     * `ignored`. `sort` = a key of SORTS, `dir` = asc|desc.
     *
     * @return array{entries: list<array>, total: int}
     */
    public function search(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where  = ['ignored = :i'];
        $params = [':i' => !empty($filter['ignored']) ? 1 : 0];
        if (($q = trim((string) ($filter['q'] ?? ''))) !== '') {
            $where[] = "path LIKE :q ESCAPE '\\'";
            $params[':q'] = '%' . self::like($q) . '%';
        }
        $ref = trim((string) ($filter['ref'] ?? ''));
        if ($ref === '-') {
            $where[] = 'referrer IS NULL';
        } elseif ($ref !== '') {
            $where[] = "referrer LIKE :r ESCAPE '\\'";
            $params[':r'] = '%' . self::like($ref) . '%';
        }
        if (($min = (int) ($filter['min'] ?? 0)) > 1) {
            $where[] = 'hits >= :m';
            $params[':m'] = $min;
        }
        if (($days = (int) ($filter['days'] ?? 0)) > 0) {
            $where[] = 'last_seen >= :d';
            $params[':d'] = date('c', time() - $days * 86400);
        }
        $where = implode(' AND ', $where);

        $sort = self::SORTS[$filter['sort'] ?? ''] ?? 'hits';
        $dir  = strtolower((string) ($filter['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $then = $sort === 'last_seen' ? 'path ASC' : 'last_seen DESC';

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM station0_404 WHERE {$where}");
        $count->execute($params);

        $stmt = $this->pdo->prepare("SELECT path, hits, first_seen, last_seen, referrer FROM station0_404
            WHERE {$where} ORDER BY {$sort} {$dir}, {$then} LIMIT :l OFFSET :o");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':l', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':o', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'entries' => array_map(fn (array $r) => ['hits' => (int) $r['hits']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total'   => (int) $count->fetchColumn(),
        ];
    }

    /**
     * Drop the paths that something now answers (a rule, a new page), ignored
     * ones included — they are no longer misses.
     *
     * @param callable(string): bool $resolved
     * @return int rows removed
     */
    public function forgetResolved(callable $resolved): int
    {
        $gone = array_values(array_filter(
            $this->pdo->query('SELECT path FROM station0_404')->fetchAll(PDO::FETCH_COLUMN),
            fn ($path) => $resolved((string) $path),
        ));
        $stmt = $this->pdo->prepare('DELETE FROM station0_404 WHERE path = ?');
        foreach ($gone as $path) {
            $stmt->execute([$path]);
        }
        return count($gone);
    }

    private static function like(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /**
     * Hide paths from the log (they keep counting).
     *
     * @param string|list<string> $paths
     */
    public function ignore(string|array $paths, bool $ignored = true): void
    {
        $stmt = $this->pdo->prepare('UPDATE station0_404 SET ignored = ? WHERE path = ?');
        foreach ((array) $paths as $path) {
            $stmt->execute([$ignored ? 1 : 0, (string) $path]);
        }
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

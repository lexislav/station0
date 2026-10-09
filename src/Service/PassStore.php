<?php

declare(strict_types=1);

namespace Station0\Service;

use PDO;

/**
 * Public-site sign-ins ("passes") in the SQLite table `station0_passes`.
 *
 * A pass is either a `guest` pass (a time-limited grant without an account,
 * e.g. after a site action verified the visitor) or a `member` pass (a signed-in
 * user account). The browser holds only a random token; the table stores its
 * SHA-256 hash, so a leaked database cannot be replayed as cookies.
 *
 * See Visitor for the request-side API.
 */
final class PassStore
{
    public const KIND_GUEST  = 'guest';
    public const KIND_MEMBER = 'member';

    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS station0_passes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT    NOT NULL UNIQUE,
            kind       TEXT    NOT NULL,
            identity   TEXT    NOT NULL,
            user_id    INTEGER DEFAULT NULL,
            label      TEXT    NOT NULL DEFAULT '',
            meta       TEXT    NOT NULL DEFAULT '{}',
            persistent INTEGER NOT NULL DEFAULT 1,
            created    INTEGER NOT NULL,
            expires    INTEGER NOT NULL,
            last_seen  INTEGER NOT NULL,
            ip         TEXT    DEFAULT NULL,
            ua         TEXT    DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS station0_passes_expires ON station0_passes (expires);
        CREATE INDEX IF NOT EXISTS station0_passes_user ON station0_passes (user_id);
        SQL;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(private readonly PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->pdo->exec(self::SCHEMA);
    }

    /**
     * Create a pass and return its token (shown to the browser once) and row.
     *
     * @param array<string, mixed> $meta
     * @return array{token: string, pass: array<string, mixed>}
     */
    public function create(
        string $kind,
        string $identity,
        int $ttl,
        ?int $userId = null,
        string $label = '',
        array $meta = [],
        bool $persistent = true,
        ?string $ip = null,
        ?string $ua = null,
    ): array {
        if (!in_array($kind, [self::KIND_GUEST, self::KIND_MEMBER], true)) {
            throw new \InvalidArgumentException("Unknown pass kind: {$kind}");
        }
        $now   = ($this->clock)();
        $token = bin2hex(random_bytes(32));
        $stmt  = $this->pdo->prepare(
            'INSERT INTO station0_passes (token_hash, kind, identity, user_id, label, meta, persistent, created, expires, last_seen, ip, ua)
             VALUES (:h, :k, :i, :u, :l, :m, :p, :c, :e, :c, :ip, :ua)'
        );
        $stmt->execute([
            ':h'  => self::hash($token),
            ':k'  => $kind,
            ':i'  => $identity,
            ':u'  => $userId,
            ':l'  => $label,
            ':m'  => json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}',
            ':p'  => $persistent ? 1 : 0,
            ':c'  => $now,
            ':e'  => $now + max(1, $ttl),
            ':ip' => $ip,
            ':ua' => $ua !== null ? mb_substr($ua, 0, 255) : null,
        ]);

        // Housekeeping now and then — expired passes are never valid anyway.
        if (random_int(1, 50) === 1) {
            $this->purgeExpired();
        }

        return ['token' => $token, 'pass' => $this->findById((int) $this->pdo->lastInsertId())];
    }

    /** The live pass for a browser token, or null (unknown / expired). */
    public function findByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM station0_passes WHERE token_hash = :h AND expires > :now');
        $stmt->execute([':h' => self::hash($token), ':now' => ($this->clock)()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::row($row) : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM station0_passes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::row($row) : null;
    }

    /**
     * Live passes, newest first — optionally one kind only.
     *
     * @return list<array<string, mixed>>
     */
    public function active(?string $kind = null): array
    {
        $sql    = 'SELECT * FROM station0_passes WHERE expires > :now';
        $params = [':now' => ($this->clock)()];
        if ($kind !== null) {
            $sql .= ' AND kind = :k';
            $params[':k'] = $kind;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY created DESC');
        $stmt->execute($params);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Record a visit; with $expires, also move the expiry (sliding member sign-ins). */
    public function touch(int $id, ?int $expires = null): void
    {
        $now = ($this->clock)();
        if ($expires === null) {
            $stmt = $this->pdo->prepare('UPDATE station0_passes SET last_seen = :now WHERE id = :id');
            $stmt->execute([':now' => $now, ':id' => $id]);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE station0_passes SET last_seen = :now, expires = :e WHERE id = :id');
        $stmt->execute([':now' => $now, ':e' => $expires, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM station0_passes WHERE id = :id')->execute([':id' => $id]);
    }

    /** Sign a user out everywhere (e.g. after a password change or account deletion). */
    public function deleteForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM station0_passes WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        return $stmt->rowCount();
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM station0_passes WHERE expires <= :now');
        $stmt->execute([':now' => ($this->clock)()]);
        return $stmt->rowCount();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed> */
    private static function row(array $r): array
    {
        $meta = json_decode((string) $r['meta'], true);
        return [
            'id'         => (int) $r['id'],
            'kind'       => (string) $r['kind'],
            'identity'   => (string) $r['identity'],
            'userId'     => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'label'      => (string) $r['label'],
            'meta'       => is_array($meta) ? $meta : [],
            'persistent' => (bool) $r['persistent'],
            'created'    => (int) $r['created'],
            'expires'    => (int) $r['expires'],
            'lastSeen'   => (int) $r['last_seen'],
            'ip'         => $r['ip'],
            'ua'         => $r['ua'],
        ];
    }
}

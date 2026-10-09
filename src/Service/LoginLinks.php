<?php

declare(strict_types=1);

namespace Station0\Service;

use PDO;

/**
 * One-time sign-in links ("magic links") for the public site, in the SQLite
 * table `station0_login_links`. A link carries a selector (row key) and a
 * secret token; only the token's SHA-256 hash is stored, and a link works once.
 */
final class LoginLinks
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS station0_login_links (
            selector   TEXT    PRIMARY KEY,
            token_hash TEXT    NOT NULL,
            user_id    INTEGER NOT NULL,
            expires    INTEGER NOT NULL
        );
        SQL;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(private readonly PDO $pdo, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->pdo->exec(self::SCHEMA);
    }

    /** @return array{selector: string, token: string, expires: int} */
    public function create(int $userId, int $ttl = 1200): array
    {
        $now = ($this->clock)();
        $this->pdo->prepare('DELETE FROM station0_login_links WHERE expires <= :now')->execute([':now' => $now]);

        $selector = bin2hex(random_bytes(12));
        $token    = bin2hex(random_bytes(24));
        $expires  = $now + max(60, $ttl);
        $this->pdo->prepare(
            'INSERT INTO station0_login_links (selector, token_hash, user_id, expires) VALUES (:s, :h, :u, :e)'
        )->execute([':s' => $selector, ':h' => hash('sha256', $token), ':u' => $userId, ':e' => $expires]);

        return ['selector' => $selector, 'token' => $token, 'expires' => $expires];
    }

    /** The user id the link was issued for, or null (unknown, expired or already used). */
    public function consume(string $selector, string $token): ?int
    {
        if ($selector === '' || $token === '' || strlen($selector) > 64 || strlen($token) > 128) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT token_hash, user_id, expires FROM station0_login_links WHERE selector = :s');
        $stmt->execute([':s' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return null;
        }
        // Single use — a valid link is spent even if it turned out to be expired.
        $this->pdo->prepare('DELETE FROM station0_login_links WHERE selector = :s')->execute([':s' => $selector]);
        return (int) $row['expires'] > ($this->clock)() ? (int) $row['user_id'] : null;
    }
}

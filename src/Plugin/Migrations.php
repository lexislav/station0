<?php

declare(strict_types=1);

namespace Station0\Plugin;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Plugin schema changes (Registrar::migration()), applied once each and
 * tracked in `station0_migrations`. Runs when the database connection is
 * built, only if an enabled plugin declares migrations.
 */
final class Migrations
{
    public const TABLE = 'station0_migrations';

    /**
     * @param array<string, string> $migrations "{plugin}.{id}" ⇒ SQL
     * @return list<string> ids applied by this call
     */
    public static function apply(PDO $pdo, array $migrations, ?LoggerInterface $logger = null): array
    {
        if ($migrations === []) {
            return [];
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE
            . ' (id TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = array_flip($pdo->query('SELECT id FROM ' . self::TABLE)->fetchAll(PDO::FETCH_COLUMN));

        $applied = [];
        foreach ($migrations as $id => $sql) {
            if (isset($done[$id])) {
                continue;
            }
            try {
                $pdo->beginTransaction();
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO ' . self::TABLE . ' (id, applied_at) VALUES (?, ?)')
                    ->execute([$id, date('c')]);
                $pdo->commit();
                $applied[] = $id;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $logger?->error(sprintf("Migration '%s' failed: %s", $id, $e->getMessage()));
            }
        }
        return $applied;
    }
}

<?php

declare(strict_types=1);

namespace Station0\Service;

use Delight\Auth\Auth;
use PDO;

final class UserRepository
{
    public function __construct(
        private readonly Auth $auth,
        private readonly PDO $pdo,
        private readonly array $rolesMap,
    ) {}

    /**
     * @return array{id:int, email:string, created_at:int, last_login:?int, roles:string[]}[]
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT id, email, username, registered, last_login, roles_mask FROM users ORDER BY id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'username' => $r['username'] !== null ? (string) $r['username'] : null,
            'created_at' => (int) $r['registered'],
            'last_login' => $r['last_login'] !== null ? (int) $r['last_login'] : null,
            'roles' => $this->maskToNames((int) $r['roles_mask']),
        ], $rows);
    }

    public function hasAny(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function create(string $email, string $password, string $roleName, string $username): int
    {
        if (!isset($this->rolesMap[$roleName])) {
            throw new \InvalidArgumentException("Unknown role: {$roleName}");
        }
        $userId = $this->auth->admin()->createUser($email, $password, $username);
        $this->auth->admin()->addRoleForUserById($userId, $this->rolesMap[$roleName]);
        return $userId;
    }

    public function delete(int $id): void
    {
        $this->auth->admin()->deleteUserById($id);
    }

    public function setPassword(int $id, string $newPassword): void
    {
        $this->auth->admin()->changePasswordForUserById($id, $newPassword);
    }

    /** @return string[] */
    private function maskToNames(int $mask): array
    {
        $out = [];
        foreach ($this->rolesMap as $name => $value) {
            if (($mask & $value) === $value) {
                $out[] = $name;
            }
        }
        return $out;
    }
}

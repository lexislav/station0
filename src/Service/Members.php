<?php

declare(strict_types=1);

namespace Station0\Service;

use Delight\Auth\Auth;
use Delight\Auth\DuplicateUsernameException;
use Delight\Auth\PasswordHash;
use Delight\Auth\Status;
use PDO;

/**
 * User accounts as seen by the public site (members-only access, see
 * Visitor and MemberAuthController). Accounts live in the same `users`
 * table as admin users — any account may sign in on the public site, but
 * only the roles in `admin.roles` may enter the admin.
 *
 * Public sign-ins never use the delight-im session (that one belongs to the
 * admin); passwords are checked here and the visitor gets a member pass.
 */
final class Members
{
    public const MIN_PASSWORD = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Auth $auth,
        private readonly array $rolesMap,
    ) {}

    /** @return array{id:int, email:string, username:?string, roles:list<string>, active:bool}|null */
    public function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, email, username, status, verified, roles_mask FROM users WHERE email = :e');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->row($row) : null;
    }

    /** @return array{id:int, email:string, username:?string, roles:list<string>, active:bool}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, username, status, verified, roles_mask FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->row($row) : null;
    }

    /**
     * The active account for these credentials, or null. Callers throttle
     * attempts (RateLimiter) — this check itself is not rate limited.
     *
     * @return array{id:int, email:string, username:?string, roles:list<string>, active:bool}|null
     */
    public function verify(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, username, password, status, verified, roles_mask FROM users WHERE email = :e');
        $stmt->execute([':e' => trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Same work as a real check, so timing does not reveal unknown emails.
            static $dummy = null;
            $dummy ??= password_hash('station0-dummy', PASSWORD_DEFAULT);
            password_verify($password, $dummy);
            return null;
        }
        if (!PasswordHash::verify($password, (string) $row['password'])) {
            return null;
        }
        $member = $this->row($row);
        return $member['active'] ? $member : null;
    }

    /**
     * Create an account (role `member` by default). The username is optional
     * and dropped when another account already uses it.
     */
    public function create(string $email, string $password, ?string $username = null, string $role = 'member'): int
    {
        if ($this->auth === null) {
            throw new \RuntimeException('Members::create needs the auth service.');
        }
        if (!isset($this->rolesMap[$role])) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Password too short.');
        }
        $username = $username !== null && trim($username) !== '' ? mb_substr(trim($username), 0, 100) : null;
        try {
            $id = $this->auth->admin()->createUserWithUniqueUsername(trim($email), $password, $username);
        } catch (DuplicateUsernameException) {
            $id = $this->auth->admin()->createUser(trim($email), $password, null);
        }
        $this->auth->admin()->addRoleForUserById($id, $this->rolesMap[$role]);
        return (int) $id;
    }

    public function setPassword(int $id, string $password): void
    {
        if ($this->auth === null) {
            throw new \RuntimeException('Members::setPassword needs the auth service.');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Password too short.');
        }
        $this->auth->admin()->changePasswordForUserById($id, $password);
    }

    /** @return array{id:int, email:string, username:?string, roles:list<string>, active:bool} */
    private function row(array $r): array
    {
        $mask  = (int) $r['roles_mask'];
        $roles = [];
        foreach ($this->rolesMap as $name => $value) {
            if (($mask & $value) === $value) {
                $roles[] = $name;
            }
        }
        return [
            'id'       => (int) $r['id'],
            'email'    => (string) $r['email'],
            'username' => $r['username'] !== null && $r['username'] !== '' ? (string) $r['username'] : null,
            'roles'    => $roles,
            'active'   => (int) $r['status'] === Status::NORMAL && (int) $r['verified'] === 1,
        ];
    }
}

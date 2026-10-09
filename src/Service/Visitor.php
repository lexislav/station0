<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Who is viewing the public site — the request-side half of members-only
 * access (config `access`, see AccessPolicy).
 *
 * A visitor is one of:
 *   - anonymous;
 *   - a guest: holds a time-limited pass granted by a site action
 *     (grantPass), no account;
 *   - a member: signed in with an account (signInMember), either for the
 *     browser session or "remembered" for `access.rememberDays` (sliding);
 *   - staff: an admin/editor signed into the admin counts as a member too.
 *
 * The browser keeps one HttpOnly cookie (`station0_pass`); VisitorMiddleware
 * loads it per request and writes the cookies queued here onto the response.
 * Templates get the `visitor` global (see toTwig()).
 */
final class Visitor
{
    public const COOKIE = 'station0_pass';

    /** Refresh last_seen at most this often (seconds) — avoids a write per request. */
    private const TOUCH_EVERY = 300;

    private ?array $pass = null;
    private ?array $staff = null;
    private bool $staffLoaded = false;

    /** @var list<string> Set-Cookie header values to send */
    private array $cookies = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param array{secure?: bool, sameSite?: string, rememberDays?: int, sessionHours?: int} $options
     * @param \Closure(): (array{userId: int, email: string, label: string}|null)|null $staffIdentity
     *        the admin user signed into the admin, if they may count as a member
     */
    public function __construct(
        private readonly PassStore $passes,
        private readonly ?Members $members = null,
        private readonly array $options = [],
        private readonly ?\Closure $staffIdentity = null,
        ?\Closure $clock = null,
        private readonly ?string $ip = null,
        private readonly ?string $userAgent = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Read the pass cookie of this request (VisitorMiddleware). */
    public function load(array $cookies): void
    {
        $this->pass = null;
        $token = isset($cookies[self::COOKIE]) && is_string($cookies[self::COOKIE]) ? $cookies[self::COOKIE] : '';
        if ($token === '') {
            return;
        }
        $pass = $this->passes->findByToken($token);
        if ($pass !== null && $pass['userId'] !== null && $this->members !== null) {
            // The account was deleted or deactivated since.
            $member = $this->members->findById($pass['userId']);
            if ($member === null || !$member['active']) {
                $this->passes->delete($pass['id']);
                $pass = null;
            }
        }
        if ($pass === null) {
            $this->queueCookie('', 1); // stale cookie — drop it
            return;
        }

        $now = ($this->clock)();
        if ($pass['kind'] === PassStore::KIND_MEMBER && $pass['persistent']) {
            // Sliding "remember me": renew once less than half the period is left.
            $ttl = $this->rememberTtl();
            if ($pass['expires'] - $now < intdiv($ttl, 2)) {
                $pass['expires'] = $now + $ttl;
                $this->passes->touch($pass['id'], $pass['expires']);
                $this->queueCookie($token, $pass['expires']);
            } elseif ($now - $pass['lastSeen'] > self::TOUCH_EVERY) {
                $this->passes->touch($pass['id']);
            }
        } elseif ($now - $pass['lastSeen'] > self::TOUCH_EVERY) {
            $this->passes->touch($pass['id']);
        }
        $this->pass = $pass;
    }

    /**
     * The current identity, or null for an anonymous visitor.
     *
     * @return array{kind: string, identity: string, userId: ?int, email: ?string, label: string, expiresAt: ?int, staff: bool, meta: array}|null
     */
    public function current(): ?array
    {
        if ($this->pass !== null) {
            return [
                'kind'      => $this->pass['kind'],
                'identity'  => $this->pass['identity'],
                'userId'    => $this->pass['userId'],
                'email'     => isset($this->pass['meta']['email']) ? (string) $this->pass['meta']['email'] : null,
                'label'     => $this->pass['label'],
                'expiresAt' => $this->pass['persistent'] || $this->pass['kind'] === PassStore::KIND_GUEST ? $this->pass['expires'] : null,
                'staff'     => false,
                'meta'      => $this->pass['meta'],
            ];
        }
        $staff = $this->staff();
        if ($staff !== null) {
            return [
                'kind'      => PassStore::KIND_MEMBER,
                'identity'  => 'user:' . $staff['userId'],
                'userId'    => $staff['userId'],
                'email'     => $staff['email'],
                'label'     => $staff['label'],
                'expiresAt' => null,
                'staff'     => true,
                'meta'      => [],
            ];
        }
        return null;
    }

    public function isAuthenticated(): bool
    {
        return $this->current() !== null;
    }

    public function isMember(): bool
    {
        return ($this->current()['kind'] ?? null) === PassStore::KIND_MEMBER;
    }

    public function isGuest(): bool
    {
        return ($this->current()['kind'] ?? null) === PassStore::KIND_GUEST;
    }

    /**
     * Let this browser in for $ttl seconds without an account. Replaces the
     * visitor's current pass. $identity is the site's own key for the person
     * (e.g. "member:jan"); $meta is stored with the pass.
     */
    public function grantPass(string $identity, int $ttl, string $label = '', array $meta = []): array
    {
        return $this->issue(PassStore::KIND_GUEST, $identity, $ttl, null, $label, $meta, true);
    }

    /**
     * Sign an account in on the public site. Remembered: a sliding pass for
     * `access.rememberDays`; otherwise until the browser closes (at most
     * `access.sessionHours`).
     */
    public function signInMember(int $userId, bool $remember = true): array
    {
        $member = $this->members?->findById($userId);
        if ($this->members !== null && ($member === null || !$member['active'])) {
            throw new \InvalidArgumentException("No active account #{$userId}.");
        }
        $email = $member['email'] ?? '';
        $label = $member['username'] ?? ($email !== '' ? strstr($email, '@', true) : '');
        $ttl   = $remember ? $this->rememberTtl() : max(1, (int) ($this->options['sessionHours'] ?? 12)) * 3600;
        return $this->issue(PassStore::KIND_MEMBER, 'user:' . $userId, $ttl, $userId, (string) $label, ['email' => $email], $remember);
    }

    /** End the public sign-in (the admin session, if any, is left alone). */
    public function signOut(): void
    {
        if ($this->pass !== null) {
            $this->passes->delete($this->pass['id']);
        }
        $this->pass = null;
        $this->queueCookie('', 1);
    }

    /** @return list<string> Set-Cookie header values queued during this request */
    public function pendingCookies(): array
    {
        return $this->cookies;
    }

    /** The `visitor` Twig global. */
    public function toTwig(): array
    {
        $cur = $this->current();
        return [
            'authenticated' => $cur !== null,
            'kind'          => $cur['kind'] ?? null,
            'member'        => ($cur['kind'] ?? null) === PassStore::KIND_MEMBER,
            'guest'         => ($cur['kind'] ?? null) === PassStore::KIND_GUEST,
            'staff'         => $cur['staff'] ?? false,
            'identity'      => $cur['identity'] ?? null,
            'userId'        => $cur['userId'] ?? null,
            'email'         => $cur['email'] ?? null,
            'label'         => $cur['label'] ?? null,
            'expiresAt'     => $cur['expiresAt'] ?? null,
            'meta'          => $cur['meta'] ?? [],
        ];
    }

    private function issue(string $kind, string $identity, int $ttl, ?int $userId, string $label, array $meta, bool $persistent): array
    {
        if ($this->pass !== null) {
            $this->passes->delete($this->pass['id']);
        }
        $created = $this->passes->create(
            $kind, $identity, $ttl, $userId, $label, $meta, $persistent, $this->ip, $this->userAgent,
        );
        $this->pass = $created['pass'];
        $this->queueCookie($created['token'], $persistent ? $this->pass['expires'] : 0);
        return $this->pass;
    }

    private function staff(): ?array
    {
        if (!$this->staffLoaded) {
            $this->staffLoaded = true;
            $this->staff = $this->staffIdentity !== null ? ($this->staffIdentity)() : null;
        }
        return $this->staff;
    }

    private function rememberTtl(): int
    {
        return max(1, (int) ($this->options['rememberDays'] ?? 365)) * 86400;
    }

    /** $expires: unix time, 0 = session cookie, 1 = delete. */
    private function queueCookie(string $value, int $expires): void
    {
        $parts = [self::COOKIE . '=' . rawurlencode($value), 'Path=/', 'HttpOnly'];
        if ($expires === 1) {
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:01 GMT';
            $parts[] = 'Max-Age=0';
        } elseif ($expires > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $expires - ($this->clock)());
        }
        if (!empty($this->options['secure'])) {
            $parts[] = 'Secure';
        }
        $parts[] = 'SameSite=' . ($this->options['sameSite'] ?? 'Lax');
        // One cookie per response — a later change replaces an earlier one.
        $this->cookies = [implode('; ', $parts)];
    }
}

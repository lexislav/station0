<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Station0\Service\LoginLinks;
use Station0\Service\Members;
use Station0\Service\PassStore;
use Station0\Service\Visitor;

final class VisitorTest extends TestCase
{
    private int $now = 1_000_000;
    private PDO $pdo;
    private PassStore $passes;
    private Members $members;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/vendor/delight-im/auth/Database/SQLite.sql'));
        $this->passes  = new PassStore($this->pdo, fn () => $this->now);
        $this->members = new Members($this->pdo, null, ['admin' => 1, 'editor' => 2, 'member' => 131072]);
    }

    private function user(string $email, string $password, int $roles = 131072, ?string $username = null, int $status = 0): int
    {
        $this->pdo->prepare('INSERT INTO users (email, password, username, status, verified, resettable, roles_mask, registered, force_logout)
            VALUES (?, ?, ?, ?, 1, 1, ?, ?, 0)')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $username, $status, $roles, $this->now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function visitor(?\Closure $staff = null, array $options = []): Visitor
    {
        return new Visitor($this->passes, $this->members, $options + ['rememberDays' => 10, 'secure' => true], $staff, fn () => $this->now);
    }

    /** The token from a queued Set-Cookie header. */
    private static function token(Visitor $v): string
    {
        preg_match('/^station0_pass=([^;]*)/', $v->pendingCookies()[0] ?? '', $m);
        return rawurldecode($m[1] ?? '');
    }

    public function testAnonymousByDefault(): void
    {
        $v = $this->visitor();
        $v->load([]);
        self::assertFalse($v->isAuthenticated());
        self::assertNull($v->current());
        self::assertSame([], $v->pendingCookies());
        self::assertFalse($v->toTwig()['authenticated']);
    }

    public function testGuestPassLastsItsTtl(): void
    {
        $v = $this->visitor();
        $v->load([]);
        $v->grantPass('clen:kozel', 2 * 86400, 'Kozel', ['slug' => 'kozel']);

        self::assertTrue($v->isGuest());
        self::assertSame('Kozel', $v->current()['label']);
        $cookie = $v->pendingCookies()[0];
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('Max-Age=172800', $cookie);
        $token = self::token($v);
        self::assertSame(64, strlen($token));

        $this->now += 86400;
        $next = $this->visitor();
        $next->load([Visitor::COOKIE => $token]);
        self::assertTrue($next->isGuest());
        self::assertSame('clen:kozel', $next->current()['identity']);
        self::assertSame(['slug' => 'kozel'], $next->current()['meta']);

        $this->now += 86401;
        $expired = $this->visitor();
        $expired->load([Visitor::COOKIE => $token]);
        self::assertFalse($expired->isAuthenticated());
        self::assertStringContainsString('Max-Age=0', $expired->pendingCookies()[0], 'stale cookie is dropped');
    }

    public function testRememberedMemberSlidesAndSessionMemberDoesNot(): void
    {
        $id = $this->user('jan@example.com', 'secret-password', 131072, 'Jan');
        $v  = $this->visitor();
        $v->load([]);
        $v->signInMember($id, true);
        self::assertTrue($v->isMember());
        self::assertSame('Jan', $v->current()['label']);
        self::assertSame('jan@example.com', $v->current()['email']);
        $token = self::token($v);

        // More than half of the 10 days used up → renewed.
        $this->now += 6 * 86400;
        $later = $this->visitor();
        $later->load([Visitor::COOKIE => $token]);
        self::assertTrue($later->isMember());
        self::assertStringContainsString('Max-Age=864000', $later->pendingCookies()[0]);

        $session = $this->visitor();
        $session->load([]);
        $session->signInMember($id, false);
        self::assertStringNotContainsString('Max-Age', $session->pendingCookies()[0], 'browser-session cookie');
        self::assertNull($session->current()['expiresAt']);
    }

    public function testDeletedAccountEndsThePassAndSignOutRevokes(): void
    {
        $id = $this->user('eva@example.com', 'secret-password');
        $v  = $this->visitor();
        $v->load([]);
        $v->signInMember($id);
        $token = self::token($v);

        $v->signOut();
        self::assertFalse($v->isAuthenticated());
        $again = $this->visitor();
        $again->load([Visitor::COOKIE => $token]);
        self::assertFalse($again->isAuthenticated(), 'revoked server-side');

        $v->signInMember($id);
        $token = self::token($v);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);
        $gone = $this->visitor();
        $gone->load([Visitor::COOKIE => $token]);
        self::assertFalse($gone->isAuthenticated());
        self::assertSame([], $this->passes->active());
    }

    public function testStaffCountsAsMemberWithoutAPass(): void
    {
        $v = $this->visitor(fn () => ['userId' => 1, 'email' => 'admin@example.com', 'label' => 'admin']);
        $v->load([]);
        self::assertTrue($v->isMember());
        self::assertTrue($v->toTwig()['staff']);
        self::assertSame([], $v->pendingCookies());
    }

    public function testMembersVerifyChecksPasswordAndStatus(): void
    {
        $this->user('ok@example.com', 'right-password', 131072);
        $this->user('banned@example.com', 'right-password', 131072, null, 2);

        self::assertSame('ok@example.com', $this->members->verify('ok@example.com', 'right-password')['email']);
        self::assertSame(['member'], $this->members->verify('OK@example.com', 'right-password')['roles']);
        self::assertNull($this->members->verify('ok@example.com', 'wrong'));
        self::assertNull($this->members->verify('nobody@example.com', 'right-password'));
        self::assertNull($this->members->verify('banned@example.com', 'right-password'));
    }

    public function testLoginLinksWorkOnceAndExpire(): void
    {
        $links = new LoginLinks($this->pdo, fn () => $this->now);
        $link  = $links->create(7, 600);

        self::assertNull($links->consume($link['selector'], 'wrong-token'));
        self::assertSame(7, $links->consume($link['selector'], $link['token']));
        self::assertNull($links->consume($link['selector'], $link['token']), 'single use');

        $old = $links->create(8, 600);
        $this->now += 601;
        self::assertNull($links->consume($old['selector'], $old['token']));
    }
}

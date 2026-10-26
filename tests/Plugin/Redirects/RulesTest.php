<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PHPUnit\Framework\TestCase;
use Station0\Plugin\Redirects\Rules;

final class RulesTest extends TestCase
{
    private function errors(array $raw, array $reserved = ['/admin', '/thumb', '/login']): array
    {
        return Rules::validate(Rules::normalize($raw), $reserved);
    }

    public function testTypeIsInferredAndPathsNormalized(): void
    {
        self::assertSame('exact', Rules::normalize(['from' => '/a/', 'to' => '/b'])['type']);
        self::assertSame('/a', Rules::normalize(['from' => 'a//', 'to' => '/b'])['from']);
        self::assertSame('pattern', Rules::normalize(['from' => '/blog/{slug}', 'to' => '/b'])['type']);
        self::assertSame('regex', Rules::normalize(['from' => '^/p/(\d+)$', 'type' => 'regex', 'to' => '/x'])['type']);
        self::assertNull(Rules::normalize(['from' => '/gone', 'to' => '/x', 'status' => 410])['to']);
        self::assertSame('2026-10-26', Rules::normalize(['from' => '/a', 'to' => '/b', 'auto' => strtotime('2026-10-26 12:00')])['auto']);
    }

    public function testValidRulesPass(): void
    {
        foreach ([
            ['from' => '/old', 'to' => '/new'],
            ['from' => '/blog/{year}/{slug}', 'to' => '/articles/{slug}'],
            ['from' => '/docs/{path*}', 'to' => 'https://docs.example.com/{path}?ref={path}'],
            ['from' => '^/p/(\d+)$', 'type' => 'regex', 'to' => '/products/$1', 'status' => 302],
            ['from' => '/discontinued', 'status' => 410],
        ] as $raw) {
            self::assertSame([], $this->errors($raw), json_encode($raw));
        }
    }

    public function testOpenRedirectThroughCapturesIsRejected(): void
    {
        self::assertNotSame([], $this->errors(['from' => '/go/{host}', 'to' => 'https://{host}/x']));
        self::assertNotSame([], $this->errors(['from' => '^/go/(.+)$', 'type' => 'regex', 'to' => 'https://$1']));
        self::assertNotSame([], $this->errors(['from' => '/go/{host}', 'to' => '//{host}']));
        self::assertNotSame([], $this->errors(['from' => '/go', 'to' => 'javascript:alert(1)']));
        self::assertNotSame([], $this->errors(['from' => '/go', 'to' => '/\\evil.com']));
    }

    public function testReservedSourcesAreRejected(): void
    {
        self::assertContains('"from" cannot be under /admin.', $this->errors(['from' => '/admin/pages', 'to' => '/x']));
        self::assertNotSame([], $this->errors(['from' => '/thumb/{x*}', 'to' => '/x']));
        self::assertNotSame([], $this->errors(['from' => '/login', 'to' => '/x']));
        self::assertNotSame([], $this->errors(['from' => '^/admin/(.*)$', 'type' => 'regex', 'to' => '/x']));
        self::assertSame([], $this->errors(['from' => '/administration', 'to' => '/x']));
    }

    public function testBadRegexGetsAReadableError(): void
    {
        self::assertSame(['A regex must be anchored: ^…$.'], $this->errors(['from' => '/p/(\d+)', 'type' => 'regex', 'to' => '/x']));
        $errors = $this->errors(['from' => '^/p/(\d+$', 'type' => 'regex', 'to' => '/x']);
        self::assertStringStartsWith('Invalid regex', $errors[0]);
        self::assertStringContainsString('Named groups', $this->errors(['from' => '^/p/(?<id>\d+)$', 'type' => 'regex', 'to' => '/x'])[0]);
    }

    public function testPlaceholderAndStatusChecks(): void
    {
        self::assertContains('"to" uses {slug}, which "from" does not define.', $this->errors(['from' => '/a/{id}', 'to' => '/b/{slug}']));
        self::assertNotSame([], $this->errors(['from' => '/a', 'to' => '/b', 'status' => 303]));
        self::assertNotSame([], $this->errors(['from' => '/a']));
        self::assertNotSame([], $this->errors(['from' => '/a/{x}/{x}', 'to' => '/b']));
        self::assertNotSame([], $this->errors(['from' => '/a/{x}', 'to' => '/b/$1']));
    }

    public function testTargetSubstitutionEncodingAndQuery(): void
    {
        $pattern = Rules::normalize(['from' => '/docs/{path*}', 'to' => '/manual/{path}']);
        self::assertSame('/manual/a/b%20c?utm=1', Rules::target($pattern, [1 => 'a/b c'], 'utm=1'));
        self::assertSame('/manual', Rules::target($pattern, [], ''));

        $drop = Rules::normalize(['from' => '/a', 'to' => '/b?x=1', 'query' => 'drop']);
        self::assertSame('/b?x=1', Rules::target($drop, [], 'utm=1'));
        $keep = Rules::normalize(['from' => '/a', 'to' => '/b?x=1']);
        self::assertSame('/b?x=1&utm=1', Rules::target($keep, [], 'utm=1'));

        // A capture can never turn a local target into //host
        $evil = Rules::normalize(['from' => '/x/{a*}', 'to' => '/{a}']);
        self::assertSame('/evil.com/path', Rules::target($evil, [1 => '/evil.com/path'], ''));

        $search = Rules::normalize(['from' => '/s/{q}', 'to' => '/search?q={q}']);
        self::assertSame('/search?q=a%26b', Rules::target($search, [1 => 'a&b'], ''));
    }

    public function testSubtreeDetection(): void
    {
        self::assertSame(['/old', '/new'], Rules::subtree(Rules::normalize(['from' => '/old/{path*}', 'to' => '/new/{path}'])));
        self::assertNull(Rules::subtree(Rules::normalize(['from' => '/old/{path*}', 'to' => '/new'])));
        self::assertNull(Rules::subtree(Rules::normalize(['from' => '/old', 'to' => '/new'])));
    }
}

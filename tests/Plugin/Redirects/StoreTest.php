<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Redirects;

use PHPUnit\Framework\TestCase;
use Station0\Plugin\Redirects\Store;

final class StoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-redirects-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function store(): Store
    {
        return new Store($this->dir . '/_redirects.yaml', $this->dir . '/cache/redirects.php', fn () => ['/admin']);
    }

    /** @return list<string> "from → to" in file order */
    private function rules(Store $store): array
    {
        return array_map(fn (array $r) => $r['from'] . ' → ' . ($r['to'] ?? $r['status']), $store->load()['rules']);
    }

    public function testSaveWritesYamlAndCompiles(): void
    {
        $store = $this->store();
        $store->save(['from' => '/old', 'to' => '/new']);
        $store->save(['from' => '/gone', 'status' => 410]);

        $yaml = (string) file_get_contents($this->dir . '/_redirects.yaml');
        self::assertStringStartsWith('# Redirect rules', $yaml);
        self::assertStringContainsString("- from: /old\n  to: /new\n", $yaml);
        self::assertStringContainsString("status: 410", $yaml);
        self::assertSame('/new', $this->store()->matcher()->match('/old')['location']);
    }

    public function testDuplicateLoopsAndInvalidRulesAreRefused(): void
    {
        $store = $this->store();
        $store->save(['from' => '/a', 'to' => '/b']);
        $store->save(['from' => '/b', 'to' => '/c']);

        $refused = fn (array $raw) => (function () use ($store, $raw) {
            try {
                $store->save($raw);
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }
            return null;
        })();

        self::assertSame('A rule for this source already exists.', $refused(['from' => '/a/', 'to' => '/x']));
        self::assertStringContainsString('redirect loop: /c → /a → /b → /c', $refused(['from' => '/c', 'to' => '/a']));
        self::assertStringContainsString('loop', $refused(['from' => '/self', 'to' => '/self']));
        self::assertStringContainsString('cannot be under /admin', $refused(['from' => '/admin/x', 'to' => '/y']));
        self::assertSame(['/a → /b', '/b → /c'], $this->rules($store));
    }

    public function testReplaceKeepsPositionAndRemoveDeletes(): void
    {
        $store = $this->store();
        $a = $store->save(['from' => '/a', 'to' => '/x']);
        $store->save(['from' => '/b', 'to' => '/y']);
        $store->save(['from' => '/a2', 'to' => '/z'], $a['id']);

        self::assertSame(['/a2 → /z', '/b → /y'], $this->rules($store));
        self::assertTrue($store->remove($store->load()['rules'][1]['id']));
        self::assertFalse($store->remove('nope'));
        self::assertSame(['/a2 → /z'], $this->rules($store));
    }

    public function testMoveAddsOneSubtreeRuleAndFlattensChains(): void
    {
        $store = $this->store();
        $store->save(['from' => '/old-team', 'to' => '/team/lead']);

        $store->recordMove('/team', '/about/team', 'Renamed in admin');
        self::assertSame(['/old-team → /about/team/lead', '/team/{path*} → /about/team/{path}'], $this->rules($store));
        $auto = $store->load()['rules'][1];
        self::assertSame(date('Y-m-d'), $auto['auto']);
        self::assertSame('Renamed in admin', $auto['note']);

        // Second move: A→B then B→C leaves A→C and B→C, never a chain.
        $store->recordMove('/about/team', '/people');
        self::assertSame([
            '/old-team → /people/lead',
            '/team/{path*} → /people/{path}',
            '/about/team/{path*} → /people/{path}',
        ], $this->rules($store));

        $m = $this->store()->matcher();
        self::assertSame('/people/lead/x', $m->match('/team/lead/x')['location']);
        self::assertSame('/people', $m->match('/about/team')['location']);
        self::assertSame(['/people/lead'], array_map(fn ($i) => $i['via'] ?? $i['rule']['to'], [$m->incoming('/people/lead')[0]]));
        self::assertCount(3, $m->incoming('/people/lead'));
    }

    public function testRenamingBackRemovesTheRule(): void
    {
        $store = $this->store();
        $store->recordMove('/a', '/b');
        $store->recordMove('/b', '/a');
        self::assertSame(['/b/{path*} → /a/{path}'], $this->rules($store));

        $store->recordMove('/a', '/b');
        self::assertSame(['/a/{path*} → /b/{path}'], $this->rules($store));
    }

    public function testHandEditedRulesAndStaleCompiledFile(): void
    {
        file_put_contents($this->dir . '/_redirects.yaml', "- from: /x\n  to: /y\n- from: /bad\n  to: nowhere\n");
        $store = $this->store();
        self::assertSame('/y', $store->matcher()->match('/x')['location']);
        self::assertCount(1, $store->load()['invalid']);

        // A later hand edit is picked up once the YAML is newer than the compiled file.
        file_put_contents($this->dir . '/_redirects.yaml', "- from: /x\n  to: /z\n");
        touch($this->dir . '/_redirects.yaml', time() + 5);
        self::assertSame('/z', $this->store()->matcher()->match('/x')['location']);

        // The per-request force check reads only the small force file, without a stat.
        file_put_contents($this->dir . '/_redirects.yaml', "- from: /x\n  to: /w\n  force: true\n");
        touch($this->dir . '/_redirects.yaml', time() + 10);
        self::assertFalse($this->store()->forceMatcher()->hasForce());
        self::assertSame('/w', $this->store()->matcher()->match('/x', '', true)['location']);   // recompiled
        self::assertTrue($this->store()->forceMatcher()->hasForce());
        self::assertCount(1, (include $this->dir . '/cache/redirects.force.php')['rules']);
    }

    public function testInvalidRulesAreKeptAndBrokenYamlIsNeverOverwritten(): void
    {
        file_put_contents($this->dir . '/_redirects.yaml', "- from: /bad\n  to: nowhere\n");
        $store = $this->store();
        $store->save(['from' => '/ok', 'to' => '/fine']);
        self::assertStringContainsString('to: nowhere', (string) file_get_contents($this->dir . '/_redirects.yaml'));

        file_put_contents($this->dir . '/_redirects.yaml', "- from: [unclosed\n");
        $this->expectException(\RuntimeException::class);
        try {
            $this->store()->save(['from' => '/a', 'to' => '/b']);
        } finally {
            self::assertSame("- from: [unclosed\n", file_get_contents($this->dir . '/_redirects.yaml'));
        }
    }

    public function testForConfigForSiteTasks(): void
    {
        $store = Store::forConfig(['adminPath' => '/admin', 'paths' => ['content' => $this->dir, 'cache' => $this->dir . '/cache']]);
        $store->recordMove('/old', '/new', 'Renamed by the import task');
        self::assertSame('/new/x', $this->store()->matcher()->match('/old/x')['location']);
        self::assertContains('/admin', $store->reserved());
    }

    public function testImport(): void
    {
        $store = $this->store();
        $store->save(['from' => '/exists', 'to' => '/x']);
        $result = $store->import([
            ['from', 'to', 'status'],
            ['/index.php?id=12', '/about', ''],
            ['/2019/05/post.html', '/blog/post', '308'],
            ['/exists', '/y', ''],
            ['/admin/x', '/y', ''],
            ['/old-shop', '', '410'],
        ]);
        self::assertSame(3, $result['added']);
        self::assertCount(2, $result['errors']);
        self::assertSame(['/exists → /x', '/index.php → /about', '/2019/05/post.html → /blog/post', '/old-shop → 410'], $this->rules($store));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Tests\Service;

use PHPUnit\Framework\TestCase;
use Station0\Service\ActionRegistry;
use Station0\Service\ActionState;

final class ActionRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/s0-actions-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function action(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', "<?php\n" . $php);
    }

    private function make(): ActionRegistry
    {
        return new ActionRegistry($this->dir, ['/admin', '/media', '/login']);
    }

    public function testLoadsDefinitionsWithDefaultsAndSkipsHelpers(): void
    {
        $this->action('contact', "return ['path' => 'contact/send/', 'handler' => fn () => null];");
        $this->action('poll', "return ['path' => '/poll', 'methods' => ['get', 'POST'], 'access' => 'members', 'handler' => fn () => null];");
        $this->action('_helpers', 'function helper() {}');

        $actions = $this->make()->all();

        self::assertSame(['contact', 'poll'], array_column($actions, 'name'));
        self::assertSame('/contact/send', $actions[0]['path']);
        self::assertSame(['POST'], $actions[0]['methods']);
        self::assertSame('public', $actions[0]['access']);
        self::assertNull($actions[0]['error']);
        self::assertSame(['GET', 'POST'], $actions[1]['methods']);
        self::assertSame('members', $actions[1]['access']);
        self::assertInstanceOf(\Closure::class, $actions[1]['handler']);
    }

    public function testInvalidDefinitionsGetAnError(): void
    {
        $this->action('no-handler', "return ['path' => '/x'];");
        $this->action('throws', "throw new RuntimeException('boom');");
        $this->action('variable', "return ['path' => '/x/{id}', 'handler' => fn () => null];");
        $this->action('reserved', "return ['path' => '/admin/hack', 'handler' => fn () => null];");
        $this->action('login', "return ['path' => '/login', 'handler' => fn () => null];");
        $this->action('method', "return ['path' => '/y', 'methods' => ['TRACE'], 'handler' => fn () => null];");
        $this->action('access', "return ['path' => '/z', 'access' => 'vip', 'handler' => fn () => null];");
        $this->action('root', "return ['path' => '/', 'handler' => fn () => null];");

        $registry = $this->make();
        self::assertStringContainsString("callable 'handler'", $registry->find('no-handler')['error']);
        self::assertStringContainsString('boom', $registry->find('throws')['error']);
        self::assertStringContainsString('static path', $registry->find('variable')['error']);
        self::assertStringContainsString('reserved', $registry->find('reserved')['error']);
        self::assertStringContainsString('reserved', $registry->find('login')['error']);
        self::assertStringContainsString("'methods'", $registry->find('method')['error']);
        self::assertStringContainsString("'access'", $registry->find('access')['error']);
        self::assertStringContainsString('static path', $registry->find('root')['error']);
    }

    public function testDuplicateRouteKeepsTheFirstAction(): void
    {
        $this->action('a-first', "return ['path' => '/send', 'handler' => fn () => null];");
        $this->action('b-second', "return ['path' => '/send', 'handler' => fn () => null];");
        $this->action('c-get', "return ['path' => '/send', 'methods' => ['GET'], 'handler' => fn () => null];");

        $registry = $this->make();
        self::assertNull($registry->find('a-first')['error']);
        self::assertStringContainsString("'a-first'", $registry->find('b-second')['error']);
        self::assertNull($registry->find('c-get')['error']);
    }

    public function testActionStateKeepsValuesAndConsumesFlash(): void
    {
        $session = [];
        $state = new ActionState($session, 'gate');
        $state->set('step', 'question');
        $state->set(['tries' => 1, 'who' => 'jan']);
        $state->flash('error', 'wrong');

        self::assertSame('question', $state->get('step'));
        self::assertSame(['step' => 'question', 'tries' => 1, 'who' => 'jan'], $state->get());
        self::assertSame(['step' => 'question', 'tries' => 1, 'who' => 'jan', 'error' => 'wrong'], (new ActionState($session, 'gate'))->pull());
        self::assertArrayNotHasKey('error', $state->pull(), 'flash is read once');

        $state->forget('who');
        self::assertNull($state->get('who'));
        $state->clear();
        self::assertSame([], $state->get());
        self::assertSame([], (new ActionState($session, 'other'))->pull());
    }
}

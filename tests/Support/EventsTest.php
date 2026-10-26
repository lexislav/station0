<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Station0\Support\Events;

final class EventsTest extends TestCase
{
    public function testListenersGetMatchingEventsInOrder(): void
    {
        $events = new Events();
        $seen   = [];
        $events->on('page.*', function (array $payload, string $event) use (&$seen) {
            $seen[] = 'any ' . $event . ' ' . $payload['event'];
        });
        $events->on('page.saved:/blog', function (array $payload) use (&$seen) {
            $seen[] = 'blog ' . $payload['path'];
        });
        $events->on('collection.item.*:products', function (array $payload) use (&$seen) {
            $seen[] = 'products ' . $payload['slug'];
        });

        self::assertSame(2, $events->dispatch('page.saved', ['path' => '/blog/a']));
        self::assertSame(1, $events->dispatch('page.saved', ['path' => '/about']));
        self::assertSame(1, $events->dispatch('collection.item.saved', ['collection' => 'products', 'slug' => 'chair']));
        self::assertSame(0, $events->dispatch('collection.item.saved', ['collection' => 'banners', 'slug' => 'x']));

        self::assertSame([
            'any page.saved page.saved',
            'blog /blog/a',
            'any page.saved page.saved',
            'products chair',
        ], $seen);
    }

    public function testFailingListenerIsLoggedAndTheRestStillRun(): void
    {
        $log    = new TestHandler();
        $events = new Events(new Logger('t', [$log]));
        $ran    = false;
        $events->on('*', function () { throw new \RuntimeException('nope'); });
        $events->on('*', function () use (&$ran) { $ran = true; });

        $events->dispatch('page.deleted', ['path' => '/x']);

        self::assertTrue($ran);
        self::assertTrue($log->hasErrorThatContains("Listener for '*' on page.deleted failed: nope"));
    }
}

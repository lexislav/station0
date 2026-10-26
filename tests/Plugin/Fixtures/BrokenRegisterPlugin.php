<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Fixtures;

use Station0\Plugin\BasePlugin;
use Station0\Plugin\Registrar;

/** Declares a route, then fails: nothing of it may stay registered. */
final class BrokenRegisterPlugin extends BasePlugin
{
    public function name(): string
    {
        return 'broken';
    }

    public function register(Registrar $r): void
    {
        $r->publicRoutes(fn ($app) => $app->get('/broken', fn ($q, $s) => $s));
        $r->contentPanel('pages', 'x', 'X', 'x.twig', fn () => []); // invalid target
    }
}

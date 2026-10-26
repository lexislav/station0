<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Fixtures\Bundled\Hello;

use Station0\Plugin\BasePlugin;
use Station0\Plugin\Registrar;

/** Stands in for a bundled plugin (short name `hello`). */
final class Plugin extends BasePlugin
{
    public function name(): string
    {
        return 'hello';
    }

    public function register(Registrar $r): void
    {
        $r->command('hello:world', fn () => 0);
    }
}

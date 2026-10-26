<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Fixtures;

use Station0\Plugin\Plugin;
use Station0\Plugin\Registrar;

/** Must never be autoloaded: no config lists it (PluginsTest). */
final class NeverLoadedPlugin implements Plugin
{
    public function __construct(array $options = [])
    {
        throw new \LogicException('NeverLoadedPlugin was constructed');
    }

    public function name(): string
    {
        return 'never';
    }

    public function register(Registrar $r): void {}
}

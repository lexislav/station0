<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin\Fixtures;

use Station0\Plugin\Plugin;
use Station0\Plugin\Registrar;

final class ThrowingPlugin implements Plugin
{
    public function __construct(array $options = [])
    {
        throw new \RuntimeException('boom in constructor');
    }

    public function name(): string
    {
        return 'throwing';
    }

    public function register(Registrar $r): void {}
}

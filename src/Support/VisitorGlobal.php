<?php

declare(strict_types=1);

namespace Station0\Support;

use Station0\Service\Visitor;

/**
 * The `visitor` Twig global — read live, so a template rendered after a
 * sign-in or sign-out in the same request sees the new state.
 *
 *   {% if visitor.authenticated %} … {{ visitor.label }} … {% endif %}
 *
 * Keys: see Visitor::toTwig().
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class VisitorGlobal implements \ArrayAccess
{
    public function __construct(private readonly Visitor $visitor) {}

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->visitor->toTwig());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->visitor->toTwig()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('visitor is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('visitor is read-only');
    }
}

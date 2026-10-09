<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Per-visitor state of a site action, kept in the PHP session under a
 * namespace (the action name by default). Templates read it with
 * `action_state('<namespace>')`.
 *
 *   $ctx->state()->set('step', 'question');      // stays until changed/cleared
 *   $ctx->state()->flash('error', 'wrong');      // shown by the next action_state() read only
 */
final class ActionState
{
    private const ROOT  = 'station0_actions';
    private const FLASH = '_flash';

    /** @param array<string, mixed> $store the session array (by reference) */
    public function __construct(private array &$store, private readonly string $namespace) {}

    public function get(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->data();
        unset($data[self::FLASH]);
        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /** @param string|array<string, mixed> $key a key, or several key => value pairs */
    public function set(string|array $key, mixed $value = null): void
    {
        $pairs = is_array($key) ? $key : [$key => $value];
        foreach ($pairs as $k => $v) {
            $this->store[self::ROOT][$this->namespace][(string) $k] = $v;
        }
    }

    public function forget(string $key): void
    {
        unset($this->store[self::ROOT][$this->namespace][$key]);
    }

    /** A value for the next read only (e.g. a message after a redirect). */
    public function flash(string $key, mixed $value): void
    {
        $this->store[self::ROOT][$this->namespace][self::FLASH][$key] = $value;
    }

    public function clear(): void
    {
        unset($this->store[self::ROOT][$this->namespace]);
    }

    /**
     * State + flashed values, consuming the flashed ones — what templates get.
     *
     * @return array<string, mixed>
     */
    public function pull(): array
    {
        $data  = $this->data();
        $flash = (array) ($data[self::FLASH] ?? []);
        unset($data[self::FLASH], $this->store[self::ROOT][$this->namespace][self::FLASH]);
        return array_merge($data, $flash);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = $this->store[self::ROOT][$this->namespace] ?? [];
        return is_array($data) ? $data : [];
    }
}

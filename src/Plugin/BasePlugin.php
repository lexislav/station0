<?php

declare(strict_types=1);

namespace Station0\Plugin;

/**
 * Convenience base: the options from `site/config.php` merged over
 * defaults() (top-level keys) and checked by configure().
 */
abstract class BasePlugin implements Plugin
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException from configure(): the plugin is skipped
     */
    public function __construct(array $options = [])
    {
        $this->config = $this->configure(array_replace($this->defaults(), $options));
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Validate / normalize the merged options; throw \InvalidArgumentException
     * to refuse them (logged, the plugin is skipped).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function configure(array $config): array
    {
        return $config;
    }

    /** The whole config, or one key. */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }
}

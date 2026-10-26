<?php

declare(strict_types=1);

namespace Station0\Plugin;

/**
 * An optional feature enabled per site in `site/config.php`:
 *
 *   'plugins' => [
 *       'redirects' => ['log404' => true],         // bundled: Station0\Plugin\Redirects\Plugin
 *       \Acme\Sitemap\Plugin::class => [],         // Composer package
 *   ],
 *
 * The class is constructed with its options array (see BasePlugin). Unlisted
 * plugins are never loaded. @experimental — see docs/plugins.md.
 */
interface Plugin
{
    /** Short, unique name: `[a-z][a-z0-9-]*` (admin routes live under /admin/{name}). */
    public function name(): string;

    /**
     * Declare everything the plugin adds. Runs on every request for enabled
     * plugins, so it must not do I/O — build heavy things in services.
     */
    public function register(Registrar $r): void;
}

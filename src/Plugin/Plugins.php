<?php

declare(strict_types=1);

namespace Station0\Plugin;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Station0\Service\CollectionItem;
use Station0\Service\Page;
use Station0\Support\Events;
use Station0\Support\VersionConstraint;

/**
 * The enabled plugins of a site and everything they declared.
 *
 * Built once per request (Bootstrap::plugins(), also bin/console) from the
 * `plugins` config. Only listed plugins are loaded; an unknown name, a
 * missing class, a version mismatch or a failing constructor / register()
 * is logged and that plugin is skipped — the site keeps running.
 */
final class Plugins
{
    public const BUNDLED_NAMESPACE = 'Station0\\Plugin\\';

    /** Core admin path segments — a plugin's admin routes live at {adminPath}/{name}. */
    public const RESERVED_NAMES = [
        'assets', 'collection-groups', 'collections', 'forgot-password', 'groups', 'login', 'logout',
        'pages', 'reset-password', 'settings', 'setup', 'tasks', 'upload', 'upload-collection', 'users',
    ];

    /** @param array<string, Registrar> $registrars keyed by plugin name, in config order */
    private function __construct(private readonly array $registrars) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<int|string, mixed> $config the `plugins` config: name / FQCN ⇒ options
     *        (a bare list entry = no options, false = off)
     */
    public static function load(
        array $config,
        string $adminPath = '/admin',
        ?LoggerInterface $logger = null,
        string $bundledNamespace = self::BUNDLED_NAMESPACE,
    ): self {
        $registrars = [];
        foreach ($config as $key => $options) {
            if (is_int($key)) {
                [$key, $options] = [$options, []];
            }
            if ($options === false || $options === null) {
                continue;
            }
            $options = $options === true ? [] : $options;
            try {
                if (!is_string($key) || trim($key) === '') {
                    throw new \InvalidArgumentException('a plugin needs a name or class');
                }
                if (!is_array($options)) {
                    throw new \InvalidArgumentException('options must be an array');
                }
                $class = self::resolveClass($key, $bundledNamespace);
                if (!class_exists($class)) {
                    throw new \RuntimeException("class {$class} not found");
                }
                if (!is_subclass_of($class, Plugin::class)) {
                    throw new \RuntimeException("{$class} does not implement " . Plugin::class);
                }
                self::checkRequires($class, $bundledNamespace);

                $plugin = new $class($options);
                $name   = $plugin->name();
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $name) || in_array($name, self::RESERVED_NAMES, true)) {
                    throw new \RuntimeException("invalid or reserved plugin name '{$name}'");
                }
                if (isset($registrars[$name])) {
                    throw new \RuntimeException("a plugin named '{$name}' is already enabled");
                }
                // A plugin whose register() fails leaves nothing behind.
                $registrar = new Registrar($plugin, $adminPath);
                $plugin->register($registrar);
                $registrars[$name] = $registrar;
            } catch (\Throwable $e) {
                $logger?->error(sprintf("Plugin '%s' skipped: %s", is_string($key) ? $key : '?', $e->getMessage()));
            }
        }
        return new self($registrars);
    }

    /** Short name ⇒ bundled class (`redirects` ⇒ Station0\Plugin\Redirects\Plugin); anything else is a FQCN. */
    public static function resolveClass(string $name, string $bundledNamespace = self::BUNDLED_NAMESPACE): string
    {
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
            return rtrim($bundledNamespace, '\\') . '\\' . $studly . '\\Plugin';
        }
        return ltrim($name, '\\');
    }

    /**
     * A plugin from a Composer package (`type: station0-plugin`) may state
     * the station0 versions it works with in `extra.station0.requires`
     * ("^0.10"). Skipped when station0's version is unknown (dev installs).
     */
    private static function checkRequires(string $class, string $bundledNamespace): void
    {
        if (str_starts_with($class, ltrim($bundledNamespace, '\\')) || !class_exists(\Composer\InstalledVersions::class)) {
            return;
        }
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file === false) {
            return;
        }
        $file = (string) realpath($file);
        foreach (\Composer\InstalledVersions::getInstalledPackagesByType('station0-plugin') as $package) {
            $dir = \Composer\InstalledVersions::getInstallPath($package);
            $dir = $dir !== null ? realpath($dir) : false;
            if ($dir === false || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $manifest   = json_decode((string) @file_get_contents($dir . '/composer.json'), true);
            $constraint = $manifest['extra']['station0']['requires'] ?? null;
            $version    = \Composer\InstalledVersions::isInstalled('lexislav/station0')
                ? (string) \Composer\InstalledVersions::getPrettyVersion('lexislav/station0')
                : '';
            if (is_string($constraint) && VersionConstraint::isRelease($version)
                && !VersionConstraint::satisfies($version, $constraint)) {
                throw new \RuntimeException("{$package} requires station0 {$constraint}, installed {$version}");
            }
            return;
        }
    }

    // ── Enabled plugins ──────────────────────────────────────────────────

    public function isEmpty(): bool
    {
        return $this->registrars === [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->registrars);
    }

    public function has(string $name): bool
    {
        return isset($this->registrars[$name]);
    }

    public function get(string $name): ?Plugin
    {
        return ($this->registrars[$name] ?? null)?->plugin();
    }

    /** @return array<string, Registrar> */
    public function registrars(): array
    {
        return $this->registrars;
    }

    /** Give runtime callbacks access to the container (Registrar::get()). */
    public function bind(ContainerInterface $container): void
    {
        foreach ($this->registrars as $registrar) {
            $registrar->bind($container);
        }
    }

    /**
     * One kind of declaration from every plugin, in config order:
     * [plugin name, item, key within the plugin].
     *
     * @return list<array{string, mixed, int|string}>
     */
    public function all(string $kind): array
    {
        $out = [];
        foreach ($this->registrars as $name => $registrar) {
            foreach ($registrar->declarations()[$kind] ?? [] as $key => $item) {
                $out[] = [$name, $item, $key];
            }
        }
        return $out;
    }

    // ── Runtime helpers ──────────────────────────────────────────────────

    /** Register every plugin's event listeners. */
    public function subscribe(Events $events): void
    {
        foreach ($this->all('listeners') as [, [$pattern, $listener]]) {
            $events->on($pattern, $listener);
        }
    }

    /** First notFound() resolver that returns a response, else null. */
    public function resolveNotFound(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $path,
        int $status,
        ?LoggerInterface $logger = null,
    ): ?ResponseInterface {
        foreach ($this->all('notFound') as [$name, $resolver]) {
            try {
                $result = $resolver($request, $response, $path, $status);
            } catch (\Throwable $e) {
                $logger?->error(sprintf("Plugin '%s' notFound resolver failed: %s", $name, $e->getMessage()));
                continue;
            }
            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }
        return null;
    }

    /** @return array{handler: callable, help: string, plugin: string}|null */
    public function command(string $name): ?array
    {
        foreach ($this->registrars as $plugin => $registrar) {
            $command = $registrar->declarations()['commands'][$name] ?? null;
            if ($command !== null) {
                return $command + ['plugin' => $plugin];
            }
        }
        return null;
    }

    /** @return array<string, string> "{plugin}.{id}" ⇒ SQL */
    public function migrations(): array
    {
        $out = [];
        foreach ($this->all('migrations') as [$name, $sql, $id]) {
            $out[$name . '.' . $id] = $sql;
        }
        return $out;
    }

    /** @return array<string, string> plugin strings for $locale ('en' first, then the locale) */
    public function translations(string $locale): array
    {
        $out = [];
        foreach ($this->registrars as $registrar) {
            $strings = $registrar->declarations()['translations'];
            $out = array_merge($out, $strings['en'] ?? [], $locale !== 'en' ? ($strings[$locale] ?? []) : []);
        }
        return $out;
    }

    /** Does a content target (`page:/blog`, `page@article`, `collectionItem:x`) cover the entity? */
    public static function targets(string $target, string $kind, Page|CollectionItem|null $entity): bool
    {
        if (!preg_match(Registrar::TARGET_PATTERN, $target, $m) || $m[1] !== $kind) {
            return false;
        }
        $filter   = trim($m[2] ?? '');
        $template = $m[3] ?? '';
        if ($filter === '' && $template === '') {
            return true;
        }
        // Narrowed targets need a stored entity to compare with.
        if ($entity instanceof Page) {
            if ($template !== '' && $entity->template !== $template) {
                return false;
            }
            $prefix = '/' . trim($filter, '/');
            return $filter === '' || $prefix === '/' || $entity->urlPath === $prefix
                || str_starts_with($entity->urlPath, $prefix . '/');
        }
        if ($entity instanceof CollectionItem) {
            return $filter === '' || fnmatch($filter, $entity->collection);
        }
        return false;
    }
}

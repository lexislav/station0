<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Site actions — the webdesigner's own request handlers (form posts,
 * small endpoints), one PHP file per action in site/actions/. Files starting
 * with `_` are helpers and ignored. The file returns a definition:
 *
 *   return [
 *       'path'    => '/contact/send',     // static path on the public site
 *       'methods' => ['POST'],            // default ['POST']
 *       'access'  => 'public',            // 'members' = only signed-in visitors (403 otherwise)
 *       'handler' => function (ActionContext $ctx) { … return $ctx->redirect('/contact'); },
 *   ];
 *
 * POSTs are CSRF-protected like every form: include the `csrf` fields. An
 * action cannot take a path of the admin, /media, /thumb or the member
 * sign-in routes; it does shadow a page with the same path.
 */
final class ActionRegistry
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
    private const PATH_PATTERN = '#^/[A-Za-z0-9._~/-]*$#';

    /** @var array<string, array>|null */
    private ?array $actions = null;

    /** @param list<string> $reserved path prefixes actions may not use */
    public function __construct(
        private readonly string $actionsDir,
        private readonly array $reserved = [],
    ) {}

    /**
     * Every action, sorted by name. A broken definition is listed with an
     * `error` and gets no route.
     *
     * @return list<array{name: string, path: string, methods: list<string>, access: string, handler: ?\Closure, error: ?string}>
     */
    public function all(): array
    {
        return array_values($this->actions ??= $this->load());
    }

    public function find(string $name): ?array
    {
        $this->actions ??= $this->load();
        return $this->actions[$name] ?? null;
    }

    /** @return array<string, array> */
    private function load(): array
    {
        $actions = [];
        $taken   = [];
        $files   = glob(rtrim($this->actionsDir, '/') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!preg_match(self::NAME_PATTERN, $name)) {
                continue; // `_helpers.php` and friends
            }
            $action = $this->define($name, $file);
            if ($action['error'] === null) {
                foreach ($action['methods'] as $method) {
                    $key = $method . ' ' . $action['path'];
                    if (isset($taken[$key])) {
                        $action['error'] = "{$method} {$action['path']} is already handled by action '{$taken[$key]}'.";
                        break;
                    }
                }
                if ($action['error'] === null) {
                    foreach ($action['methods'] as $method) {
                        $taken[$method . ' ' . $action['path']] = $name;
                    }
                }
            }
            $actions[$name] = $action;
        }
        return $actions;
    }

    private function define(string $name, string $file): array
    {
        $action = [
            'name'    => $name,
            'path'    => '',
            'methods' => ['POST'],
            'access'  => AccessPolicy::PUBLIC,
            'handler' => null,
            'error'   => null,
        ];

        try {
            $def = (static fn (string $__file) => require $__file)($file);
        } catch (\Throwable $e) {
            $action['error'] = 'Cannot load ' . basename($file) . ': ' . $e->getMessage();
            return $action;
        }
        if (!is_array($def) || !isset($def['handler']) || !is_callable($def['handler'])) {
            $action['error'] = basename($file) . " must return an array with a callable 'handler'.";
            return $action;
        }

        $path = '/' . trim((string) ($def['path'] ?? ''), '/');
        if ($path === '/' || !preg_match(self::PATH_PATTERN, $path) || str_contains($path, '..')) {
            $action['error'] = basename($file) . ": 'path' must be a static path like '/contact/send'.";
            return $action;
        }
        foreach ($this->reserved as $prefix) {
            $prefix = '/' . trim($prefix, '/');
            if ($prefix !== '/' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $action['error'] = basename($file) . ": path {$path} is reserved ({$prefix}).";
                return $action;
            }
        }

        $methods = array_values(array_unique(array_map(
            fn ($m) => strtoupper(trim((string) $m)),
            (array) ($def['methods'] ?? ['POST']),
        )));
        if ($methods === [] || array_diff($methods, self::METHODS) !== []) {
            $action['error'] = basename($file) . ": 'methods' must be some of " . implode(', ', self::METHODS) . '.';
            return $action;
        }

        $access = (string) ($def['access'] ?? AccessPolicy::PUBLIC);
        if (!in_array($access, [AccessPolicy::PUBLIC, AccessPolicy::MEMBERS], true)) {
            $action['error'] = basename($file) . ": 'access' must be 'public' or 'members'.";
            return $action;
        }

        $action['path']    = $path;
        $action['methods'] = $methods;
        $action['access']  = $access;
        $action['handler'] = \Closure::fromCallable($def['handler']);
        return $action;
    }
}

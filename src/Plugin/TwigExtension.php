<?php

declare(strict_types=1);

namespace Station0\Plugin;

use Psr\Log\LoggerInterface;
use Station0\Service\CollectionItem;
use Station0\Service\Page;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Plugins in Twig: their own functions / filters / globals, plus the admin
 * hooks plugin_nav(), plugin_panels() and plugin_badges(). With no plugins
 * enabled the admin hooks return [] right away.
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** @var array<string, bool> */
    private array $roleCache = [];

    /**
     * @param \Closure(string $role): bool $can   current admin user has the role (admin always does)
     * @param \Closure(): array            $user  current admin user for panel data callbacks
     */
    public function __construct(
        private readonly Plugins $plugins,
        private readonly \Closure $can,
        private readonly \Closure $user,
        private readonly string $adminPath = '/admin',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getFunctions(): array
    {
        $functions = [
            new TwigFunction('plugin_nav', $this->nav(...)),
            new TwigFunction('plugin_panels', $this->panels(...), ['needs_environment' => true]),
            new TwigFunction('plugin_badges', $this->badges(...)),
        ];
        foreach ($this->plugins->all('twigFunctions') as [, $f]) {
            $functions[] = new TwigFunction($f['name'], $f['callable'], $f['options']);
        }
        return $functions;
    }

    public function getFilters(): array
    {
        $filters = [];
        foreach ($this->plugins->all('twigFilters') as [, $f]) {
            $filters[] = new TwigFilter($f['name'], $f['callable'], $f['options']);
        }
        return $filters;
    }

    public function getGlobals(): array
    {
        $globals = [];
        foreach ($this->plugins->all('twigGlobals') as [, $value, $name]) {
            $globals[$name] = $value;
        }
        return $globals;
    }

    /** @return list<array{id: string, label: string, url: string, icon: ?string, plugin: string}> */
    public function nav(): array
    {
        $items = [];
        foreach ($this->plugins->all('nav') as [$plugin, $item]) {
            if ($this->can($item['role'])) {
                $items[] = [
                    'id'     => $item['id'],
                    'label'  => $item['label'],
                    'url'    => $this->adminPath . $item['url'],
                    'icon'   => $item['icon'],
                    'plugin' => $plugin,
                ];
            }
        }
        return $items;
    }

    /**
     * Rendered panels for an edit form; $entity null = a new, unsaved one.
     *
     * @param 'page'|'collectionItem' $kind
     * @return list<array{plugin: string, id: string, label: string, html: string}>
     */
    public function panels(Environment $env, string $kind, Page|CollectionItem|null $entity = null, array $csrf = []): array
    {
        $out = [];
        foreach ($this->plugins->all('panels') as [$plugin, $panel]) {
            if (!Plugins::targets($panel['target'], $kind, $entity)
                || ($panel['role'] !== null && !$this->can($panel['role']))) {
                continue;
            }
            try {
                $data = ($panel['data'])($entity, ($this->user)());
                if ($data === null) {
                    continue;
                }
                $html = $env->render($panel['template'], [
                    'entity'    => $entity,
                    'data'      => $data,
                    'csrf'      => $csrf,
                    'panel'     => ['plugin' => $plugin, 'id' => $panel['id'], 'label' => $panel['label']],
                    'pluginUrl' => $this->adminPath . '/' . $plugin,
                ]);
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf("Plugin '%s' panel '%s' failed: %s", $plugin, $panel['id'], $e->getMessage()));
                $html = '<p class="error">Panel failed to load.</p>';
            }
            $out[] = ['plugin' => $plugin, 'id' => $panel['id'], 'label' => $panel['label'], 'html' => $html];
        }
        return $out;
    }

    /** @return list<array{plugin: string, label: string, title: string, tone: string}> */
    public function badges(Page|CollectionItem $entity): array
    {
        $kind = $entity instanceof Page ? 'page' : 'collectionItem';
        $out  = [];
        foreach ($this->plugins->all('badges') as [$plugin, $badge]) {
            if (!Plugins::targets($badge['target'], $kind, $entity)
                || ($badge['role'] !== null && !$this->can($badge['role']))) {
                continue;
            }
            try {
                $b = ($badge['provider'])($entity);
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf("Plugin '%s' badge failed: %s", $plugin, $e->getMessage()));
                continue;
            }
            if (!is_array($b) || (string) ($b['label'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'plugin' => $plugin,
                'label'  => (string) $b['label'],
                'title'  => (string) ($b['title'] ?? ''),
                'tone'   => in_array($b['tone'] ?? '', ['warn', 'danger'], true) ? $b['tone'] : 'info',
            ];
        }
        return $out;
    }

    private function can(string $role): bool
    {
        return $this->roleCache[$role] ??= ($this->can)($role);
    }
}

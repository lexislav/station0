<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Slug;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Admin menu groups: own sidebar tabs for collections and page subtrees.
 *
 * Collections join inline — in a collection's _collection.yaml:
 *   label: Products
 *   group: Shop            ← group id = Slug::sanitize('Shop') = 'shop', label 'Shop'
 *
 * Pages join with front matter on the root of a subtree:
 *   Title: Downloads
 *   Group: Downloads       ← the page and every page below it
 * A page belongs to its nearest grouped ancestor-or-self. Grouped pages stay
 * in the Structure tree; the tab is a shortcut into their subtree.
 *
 * Central (optional, for extra settings) — site/content/_groups.yaml
 * (the older site/content/collections/_groups.yaml is still read):
 *   shop:
 *     label: E-shop        ← overrides the inline label
 *     icon: 🛒             ← text/emoji, or inline <svg …> markup
 *     roles: [editor]      ← who sees the tab (admins always do); omit = everyone.
 *                            Enforced for collections; for pages it only hides the tab.
 *
 * A member joins a group when its `group` slugifies to the group id, so
 * `group: shop` and `group: Shop` both reference the central `shop` entry.
 * Tabs follow the _groups.yaml order, then inline-only groups by label.
 * Groups without members produce no tab. Grouped collections are removed from
 * the generic "Collections" tab, grouped streams from the "Streams" tab.
 */
final class NavGroups
{
    public const FILE = '_groups.yaml';

    /** @var list<array>|null */
    private ?array $groups = null;

    /** @var array<string, string> page URL path → group id (only pages that declare `Group`) */
    private array $pageGroupIds = [];

    /**
     * @param \Closure(string): bool|null $hasRole role-name check for the current user;
     *                                           null = no access control (CLI, tests)
     * @param string|null $groupsFile site-wide _groups.yaml; the legacy file in the
     *                                collections directory is read after it
     */
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly ?\Closure $hasRole = null,
        private readonly ?ContentRepository $pages = null,
        private readonly ?string $groupsFile = null,
    ) {}

    /**
     * All non-empty groups, in menu order.
     *
     * @return list<array{id: string, label: string, icon: string, roles: list<string>,
     *                    collections: list<array{name: string, label: string}>,
     *                    pages: list<array{path: string, title: string}>}>
     */
    public function all(): array
    {
        return $this->groups ??= $this->build();
    }

    /** Groups the current user may see. */
    public function visible(): array
    {
        return array_values(array_filter($this->all(), fn (array $g) => $this->canAccess($g)));
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }
        return null;
    }

    /** The group a collection belongs to, or null when ungrouped. */
    public function groupOfCollection(string $collection): ?array
    {
        foreach ($this->all() as $group) {
            foreach ($group['collections'] as $col) {
                if ($col['name'] === $collection) {
                    return $group;
                }
            }
        }
        return null;
    }

    /** The group of a page: its nearest ancestor-or-self that declares one. */
    public function groupOfPage(string $urlPath): ?array
    {
        $this->all();
        $path = '/' . trim($urlPath, '/');
        while (true) {
            if (isset($this->pageGroupIds[$path])) {
                return $this->find($this->pageGroupIds[$path]);
            }
            if ($path === '/') {
                return null;
            }
            $path = rtrim(dirname($path), '/') ?: '/';
        }
    }

    /**
     * Admin path (relative to adminPath) the group's tab links to. A group made
     * of a single collection goes straight to its items.
     */
    public function path(array $group): string
    {
        return count($group['collections']) === 1 && $group['pages'] === []
            ? '/collections/' . $group['collections'][0]['name']
            : '/groups/' . $group['id'];
    }

    /** Collection rows (as CollectionRepository::collections()) of a group. */
    public function memberCollections(array $group): array
    {
        $names = array_column($group['collections'], 'name');
        return array_values(array_filter(
            $this->collections->collections(),
            fn (array $col) => in_array($col['name'], $names, true),
        ));
    }

    public function canAccess(array $group): bool
    {
        if ($group['roles'] === [] || $this->hasRole === null) {
            return true;
        }
        if (($this->hasRole)('admin')) {
            return true;
        }
        foreach ($group['roles'] as $role) {
            if (($this->hasRole)($role)) {
                return true;
            }
        }
        return false;
    }

    /** Ungrouped collections are open to every logged-in user, as before. */
    public function canAccessCollection(string $collection): bool
    {
        $group = $this->groupOfCollection($collection);
        return $group === null || $this->canAccess($group);
    }

    // ─── Build ───

    private function build(): array
    {
        $central = $this->central();

        // Seed with central definitions to keep their declared order.
        $groups = [];
        foreach ($central as $id => $def) {
            $groups[$id] = $this->makeGroup($id, $def['label'] ?? null, $def);
        }

        $inline = [];
        $join = function (string $raw) use (&$groups, &$inline): string {
            $raw = trim($raw);
            $id  = $raw === '' ? '' : Slug::sanitize($raw);
            if ($id === '') {
                return '';
            }
            // For inline groups the label is the first spelled-out value
            // (`group: E-shop`); a bare id (`group: shop`) is humanized.
            $label = $raw === $id ? null : $raw;
            if (!isset($groups[$id])) {
                $groups[$id] = $this->makeGroup($id, $label, []);
                $groups[$id]['labelSet'] = $label !== null;
                $inline[$id] = true;
            } elseif (isset($inline[$id]) && !$groups[$id]['labelSet'] && $label !== null) {
                $groups[$id]['label']    = $label;
                $groups[$id]['labelSet'] = true;
            }
            return $id;
        };

        foreach ($this->collections->names() as $name) {
            $schema = $this->collections->schema($name);
            $id     = $join((string) ($schema['group'] ?? ''));
            if ($id === '') {
                continue;
            }
            $groups[$id]['collections'][] = [
                'name'  => $name,
                'label' => (string) ($schema['label'] ?? $this->labelFromName($name)),
            ];
        }

        // Pages come in tree order (parents first), so a member whose ancestor
        // already roots the same group is covered by that subtree.
        $this->pageGroupIds = [];
        foreach ($this->pages?->all() ?? [] as $page) {
            $id = $join((string) $page->group);
            if ($id === '') {
                continue;
            }
            $covered = $this->groupOfPathIn($page->urlPath, $this->pageGroupIds) === $id;
            $this->pageGroupIds[$page->urlPath] = $id;
            if (!$covered) {
                $groups[$id]['pages'][] = ['path' => $page->urlPath, 'title' => $page->title];
            }
        }

        $groups = array_map(function (array $g) {
            unset($g['labelSet']);
            return $g;
        }, array_filter($groups, fn (array $g) => $g['collections'] !== [] || $g['pages'] !== []));

        $centralPart = array_filter($groups, fn (array $g) => !isset($inline[$g['id']]));
        $inlinePart  = array_filter($groups, fn (array $g) => isset($inline[$g['id']]));
        usort($inlinePart, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

        return array_values([...array_values($centralPart), ...$inlinePart]);
    }

    /** Group id of the nearest strict ancestor of $urlPath in $map, or null. */
    private function groupOfPathIn(string $urlPath, array $map): ?string
    {
        $path = $urlPath;
        while ($path !== '/') {
            $path = rtrim(dirname($path), '/') ?: '/';
            if (isset($map[$path])) {
                return $map[$path];
            }
        }
        return null;
    }

    private function makeGroup(string $id, ?string $label, array $def): array
    {
        $roles = $def['roles'] ?? [];
        if (is_string($roles)) {
            $roles = [$roles];
        }

        return [
            'id'          => $id,
            'label'       => $label !== null && $label !== '' ? $label : $this->labelFromName($id),
            'icon'        => trim((string) ($def['icon'] ?? '')),
            'roles'       => array_values(array_filter(array_map('strval', (array) $roles), fn ($r) => $r !== '')),
            'collections' => [],
            'pages'       => [],
        ];
    }

    /** @return array<string, array> keyed by slugified group id; the site-wide file wins */
    private function central(): array
    {
        $out = [];
        $files = [$this->groupsFile, rtrim($this->collections->directory(), '/') . '/' . self::FILE];
        foreach (array_filter($files) as $file) {
            foreach ($this->parseFile($file) as $key => $def) {
                $id = Slug::sanitize((string) $key);
                if ($id !== '' && !isset($out[$id])) {
                    $out[$id] = is_array($def) ? $def : [];
                }
            }
        }
        return $out;
    }

    private function parseFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException) {
            return [];
        }
        return is_array($parsed) ? $parsed : [];
    }

    private function labelFromName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}

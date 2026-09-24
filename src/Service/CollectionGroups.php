<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Slug;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Groups collections into their own admin menu tabs.
 *
 * Inline (enough for most cases) — in a collection's _collection.yaml:
 *   label: Products
 *   group: Shop            ← group id = Slug::sanitize('Shop') = 'shop', label 'Shop'
 *
 * Central (optional, for extra settings) — site/content/collections/_groups.yaml:
 *   shop:
 *     label: E-shop        ← overrides the inline label
 *     icon: 🛒             ← text/emoji, or inline <svg …> markup
 *     roles: [editor]      ← who sees the tab (admins always do); omit = everyone
 *
 * A collection joins a group when its `group` slugifies to the group id, so
 * `group: shop` and `group: Shop` both reference the central `shop` entry.
 * Tabs follow the _groups.yaml order, then inline-only groups by label.
 * Groups without any collection produce no tab. Grouped collections are
 * removed from the generic "Collections" tab.
 */
final class CollectionGroups
{
    public const FILE = '_groups.yaml';

    /** @var list<array>|null */
    private ?array $groups = null;

    /**
     * @param \Closure(string): bool|null $hasRole role-name check for the current user;
     *                                           null = no access control (CLI, tests)
     */
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly ?\Closure $hasRole = null,
    ) {}

    /**
     * All non-empty groups, in menu order.
     *
     * @return list<array{id: string, label: string, icon: string, roles: list<string>, collections: list<array{name: string, label: string}>}>
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
    public function groupOf(string $collection): ?array
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
        $group = $this->groupOf($collection);
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
        foreach ($this->collections->names() as $name) {
            $schema = $this->collections->schema($name);
            $raw    = trim((string) ($schema['group'] ?? ''));
            $id     = $raw === '' ? '' : Slug::sanitize($raw);
            if ($id === '') {
                continue;
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
            $groups[$id]['collections'][] = [
                'name'  => $name,
                'label' => (string) ($schema['label'] ?? $this->labelFromName($name)),
            ];
        }

        $groups = array_map(function (array $g) {
            unset($g['labelSet']);
            return $g;
        }, array_filter($groups, fn (array $g) => $g['collections'] !== []));

        $centralPart = array_filter($groups, fn (array $g) => !isset($inline[$g['id']]));
        $inlinePart  = array_filter($groups, fn (array $g) => isset($inline[$g['id']]));
        usort($inlinePart, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

        return array_values([...array_values($centralPart), ...$inlinePart]);
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
        ];
    }

    /** @return array<string, array> keyed by slugified group id */
    private function central(): array
    {
        $file = rtrim($this->collections->directory(), '/') . '/' . self::FILE;
        if (!is_file($file)) {
            return [];
        }
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException) {
            return [];
        }

        $out = [];
        foreach (is_array($parsed) ? $parsed : [] as $key => $def) {
            $id = Slug::sanitize((string) $key);
            if ($id !== '' && !isset($out[$id])) {
                $out[$id] = is_array($def) ? $def : [];
            }
        }
        return $out;
    }

    private function labelFromName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}

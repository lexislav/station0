<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Resolves the option list of `select` fields for the admin editors.
 *
 * A select field's options come either from a static list in the schema or,
 * via `options_from`, from a data source such as a collection:
 *
 *   options: [small, medium, large]            # value = label
 *   options: { s: Small, m: Medium }           # value => label
 *   options:                                   # explicit maps
 *     - { value: s, label: Small, group: Size }
 *
 *   options_from: collection:points            # one option per published item
 *   group_by: river                            # item field → <optgroup>
 *   sort_by: -km                               # item field, "-" = descending
 *   option_label: "{title} (km {km})"          # {title}, {slug}, {<field>}
 *   placeholder: "— choose —"                  # empty first option
 *
 *   options_from: collections                  # items of all collections,
 *   options_from: collections:banners,blocks   # or of the listed ones; one
 *                                              # <optgroup> per collection
 *
 *   options_from: pages                        # all published pages
 *   options_from: pages:/blog                  # only descendants of /blog
 *   template: article                          # only pages of this template (or a list)
 *
 * Stored values: `collection:` → item slug (`collection_item('<name>', value)`),
 * `collections` → "<collection>/<slug>" (`collection_item(value)`),
 * `pages` → URL path (`page(value)`).
 *
 * Label/sort/group fields: items have {title}, {slug}, {sort}, {collection}
 * and their own fields; pages have {title}, {slug}, {path}, {template},
 * {sort}, {date}, {parent}, {parent_title} and their page fields.
 *
 * Every resolved select field gets `options` (flat list of
 * {value, label, group}) and `option_groups` (list of {label, options}, in
 * first-appearance order; ungrouped options sit in a group with label '').
 */
final class FieldOptions
{
    public function __construct(
        private readonly ?CollectionRepository $collections = null,
        private readonly ?ContentRepository $pages = null,
    ) {}

    /**
     * Resolve options on every select field of a normalized (list-form) field
     * list, recursing into list `item_fields`.
     *
     * @param  list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    public function resolveFields(array $fields): array
    {
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? 'text') === 'select') {
                $options                = $this->resolve($field);
                $field['options']       = $options;
                $field['option_groups'] = self::group($options);
                if (!array_key_exists('placeholder', $field) && isset($field['options_from'])) {
                    // Referencing a data source: an empty choice must be possible,
                    // otherwise a fresh block silently points at the first item.
                    $field['placeholder'] = '—';
                }
            }
            if (isset($field['item_fields']) && is_array($field['item_fields'])) {
                $field['item_fields'] = $this->resolveFields($field['item_fields']);
            }
            $fields[$i] = $field;
        }
        return $fields;
    }

    /**
     * @param  array<string, mixed> $field
     * @return list<array{value: string, label: string, group: string}>
     */
    public function resolve(array $field): array
    {
        $source = $field['options_from'] ?? null;
        if (is_string($source) && $source !== '') {
            return $this->fromSource($source, $field);
        }
        return self::normalizeStatic($field['options'] ?? []);
    }

    /**
     * The value a select field starts with when no explicit default is set:
     * empty for data-source selects and selects with a placeholder, otherwise
     * the first static option (historic behavior).
     *
     * @param array<string, mixed> $field  raw schema definition
     */
    public static function initialValue(array $field): string
    {
        if (isset($field['default'])) {
            return (string) $field['default'];
        }
        if (isset($field['options_from']) || array_key_exists('placeholder', $field)) {
            return '';
        }
        return self::normalizeStatic($field['options'] ?? [])[0]['value'] ?? '';
    }

    /**
     * @param  mixed $raw
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function normalizeStatic(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out   = [];
        $isMap = !array_is_list($raw);
        foreach ($raw as $key => $opt) {
            if (is_array($opt)) {
                if (!isset($opt['value']) || !is_scalar($opt['value'])) {
                    continue;
                }
                $value = (string) $opt['value'];
                $out[] = [
                    'value' => $value,
                    'label' => isset($opt['label']) && is_scalar($opt['label']) ? (string) $opt['label'] : $value,
                    'group' => isset($opt['group']) && is_scalar($opt['group']) ? (string) $opt['group'] : '',
                ];
            } elseif (is_scalar($opt) || $opt === null) {
                $value = $isMap ? (string) $key : (string) $opt;
                $out[] = [
                    'value' => $value,
                    'label' => $isMap ? (string) $opt : $value,
                    'group' => '',
                ];
            }
        }
        return $out;
    }

    /**
     * @param  list<array{value: string, label: string, group: string}> $options
     * @return list<array{label: string, options: list<array{value: string, label: string, group: string}>}>
     */
    public static function group(array $options): array
    {
        $groups = [];
        foreach ($options as $opt) {
            $groups[$opt['group']][] = $opt;
        }
        $out = [];
        foreach ($groups as $label => $opts) {
            $out[] = ['label' => (string) $label, 'options' => $opts];
        }
        return $out;
    }

    // ─── Data sources ───

    /**
     * @param  array<string, mixed> $field
     * @return list<array{value: string, label: string, group: string}>
     */
    private function fromSource(string $source, array $field): array
    {
        [$kind, $arg] = array_pad(explode(':', $source, 2), 2, '');
        $records = match (strtolower(trim($kind))) {
            'collection'  => $this->collectionRecords(trim($arg)),
            'collections' => $this->collectionsRecords(trim($arg)),
            'pages'       => $this->pageRecords(trim($arg), $field['template'] ?? null),
            default       => [],
        };
        if ($records === []) {
            return [];
        }

        $groupBy  = strtolower(trim((string) ($field['group_by'] ?? '')));
        $sortBy   = trim((string) ($field['sort_by'] ?? ''));
        $labelTpl = isset($field['option_label']) && is_string($field['option_label'])
            ? $field['option_label']
            : '{title}';

        if ($sortBy !== '') {
            $records = $this->sortRecords($records, $sortBy);
        }

        $out = [];
        foreach ($records as $record) {
            $out[] = [
                'value' => $record['value'],
                'label' => $this->label($labelTpl, $record['fields']),
                'group' => $groupBy !== '' ? (string) ($record['fields'][$groupBy] ?? '') : $record['group'],
            ];
        }

        // Keep groups together (first-appearance order), options keep their order within.
        $order = [];
        foreach ($out as $opt) {
            $order[$opt['group']] ??= count($order);
        }
        if (count($order) > 1) {
            $idx = array_keys($out);
            usort($idx, fn (int $a, int $b) => [$order[$out[$a]['group']], $a] <=> [$order[$out[$b]['group']], $b]);
            $out = array_map(fn (int $i) => $out[$i], $idx);
        }

        return $out;
    }

    /**
     * A source entry: the stored value, the fields usable in `option_label`,
     * `sort_by` and `group_by` (lower-cased keys), and its default group.
     *
     * @return list<array{value: string, fields: array<string, mixed>, group: string}>
     */
    private function collectionRecords(string $name, string $group = '', bool $qualified = false): array
    {
        if ($name === '' || $this->collections === null) {
            return [];
        }
        $out = [];
        foreach ($this->collections->items($name) as $item) {
            $out[] = [
                'value'  => $qualified ? $item->collection . '/' . $item->slug : $item->slug,
                'fields' => [
                    'title'      => $item->title,
                    'slug'       => $item->slug,
                    'sort'       => $item->sort,
                    'collection' => $group !== '' ? $group : $item->collection,
                ] + $item->extra,
                'group'  => $group,
            ];
        }
        return $out;
    }

    /**
     * Items of several collections (all when $list is empty), grouped by
     * collection label; the value is "<collection>/<slug>".
     *
     * @return list<array{value: string, fields: array<string, mixed>, group: string}>
     */
    private function collectionsRecords(string $list): array
    {
        if ($this->collections === null) {
            return [];
        }
        $names = $list === ''
            ? $this->collections->names()
            : array_values(array_filter(array_map('trim', explode(',', $list)), fn (string $n) => $n !== ''));

        $out = [];
        foreach ($names as $name) {
            if (!is_dir($this->collections->directory() . '/' . $name)) {
                continue;
            }
            $label = $this->collections->schema($name)['label'] ?? $name;
            $out   = [...$out, ...$this->collectionRecords($name, is_scalar($label) ? (string) $label : $name, true)];
        }
        return $out;
    }

    /**
     * Published pages, optionally only descendants of $parent and/or of the
     * given template(s); the value is the page's URL path.
     *
     * @return list<array{value: string, fields: array<string, mixed>, group: string}>
     */
    private function pageRecords(string $parent, mixed $template): array
    {
        if ($this->pages === null) {
            return [];
        }
        $parent    = $parent === '' ? '' : '/' . trim($parent, '/');
        $templates = array_values(array_filter(
            array_map(fn ($t) => is_scalar($t) ? trim((string) $t) : '', (array) ($template ?? [])),
            fn (string $t) => $t !== '',
        ));

        $all    = $this->pages->all(false);
        $titles = [];
        foreach ($all as $page) {
            $titles[$page->urlPath] = $page->title;
        }

        $out = [];
        foreach ($all as $page) {
            if ($parent !== '' && $parent !== '/' && !str_starts_with($page->urlPath, $parent . '/')) {
                continue;
            }
            if ($parent === '/' && $page->urlPath === '/') {
                continue;
            }
            if ($templates !== [] && !in_array($page->template, $templates, true)) {
                continue;
            }
            $parentPath = $page->urlPath === '/' ? '' : (rtrim(dirname($page->urlPath), '/') ?: '/');
            $out[] = [
                'value'  => $page->urlPath,
                'fields' => [
                    'title'        => $page->title,
                    'slug'         => $page->slug,
                    'path'         => $page->urlPath,
                    'template'     => $page->template,
                    'sort'         => $page->sort,
                    'date'         => $page->publishedAt,
                    'parent'       => $parentPath,
                    'parent_title' => $parentPath !== '' ? ($titles[$parentPath] ?? $parentPath) : '',
                ] + $page->extra,
                'group'  => '',
            ];
        }
        return $out;
    }

    /**
     * Stable sort by a record field; "-field" sorts descending. Numeric values
     * (decimal comma allowed, e.g. "318,5") compare numerically.
     *
     * @param  list<array{value: string, fields: array<string, mixed>, group: string}> $records
     * @return list<array{value: string, fields: array<string, mixed>, group: string}>
     */
    private function sortRecords(array $records, string $sortBy): array
    {
        $desc = str_starts_with($sortBy, '-');
        $key  = strtolower(ltrim($sortBy, '-+'));

        $value = function (array $record) use ($key): mixed {
            $raw = $record['fields'][$key] ?? null;
            if ($raw === null || $raw === '' || !is_scalar($raw)) {
                return null;
            }
            $num = str_replace([' ', ','], ['', '.'], (string) $raw);
            return is_numeric($num) ? (float) $num : mb_strtolower((string) $raw);
        };

        $keyed = [];
        foreach ($records as $i => $record) {
            $keyed[] = [$value($record), $i, $record];
        }
        usort($keyed, function (array $a, array $b) use ($desc): int {
            // Records without a value always go last, in their original order.
            if ($a[0] === null || $b[0] === null) {
                return [$a[0] === null, $a[1]] <=> [$b[0] === null, $b[1]];
            }
            $cmp = $a[0] <=> $b[0];
            return ($desc ? -$cmp : $cmp) ?: $a[1] <=> $b[1];
        });

        return array_column($keyed, 2);
    }

    /** @param array<string, mixed> $fields */
    private function label(string $template, array $fields): string
    {
        $label = preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', function (array $m) use ($fields): string {
            $v = $fields[strtolower($m[1])] ?? '';
            return is_scalar($v) ? (string) $v : '';
        }, $template);

        $label = trim((string) $label);
        return $label !== '' ? $label : (string) $fields['title'];
    }
}

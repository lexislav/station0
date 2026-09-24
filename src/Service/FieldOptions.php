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
 * Collection-backed options store the item **slug** as the value; templates
 * look the item up with `collection_item('<name>', value)`.
 *
 * Every resolved select field gets `options` (flat list of
 * {value, label, group}) and `option_groups` (list of {label, options}, in
 * first-appearance order; ungrouped options sit in a group with label '').
 */
final class FieldOptions
{
    public function __construct(
        private readonly ?CollectionRepository $collections = null,
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
        [$kind, $name] = array_pad(explode(':', $source, 2), 2, '');
        $kind = strtolower(trim($kind));
        $name = trim($name);

        if ($kind !== 'collection' || $name === '' || $this->collections === null) {
            return [];
        }

        $items   = $this->collections->items($name);
        $groupBy = strtolower(trim((string) ($field['group_by'] ?? '')));
        $sortBy  = trim((string) ($field['sort_by'] ?? ''));
        $labelTpl = isset($field['option_label']) && is_string($field['option_label'])
            ? $field['option_label']
            : '{title}';

        if ($sortBy !== '') {
            $items = $this->sortItems($items, $sortBy);
        }

        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'value' => $item->slug,
                'label' => $this->label($labelTpl, $item),
                'group' => $groupBy !== '' ? (string) ($item->extra[$groupBy] ?? '') : '',
            ];
        }

        if ($groupBy !== '') {
            // Keep groups together (first-appearance order), items keep their order within.
            $order = [];
            foreach ($out as $opt) {
                $order[$opt['group']] ??= count($order);
            }
            $idx = array_keys($out);
            usort($idx, fn (int $a, int $b) => [$order[$out[$a]['group']], $a] <=> [$order[$out[$b]['group']], $b]);
            $out = array_map(fn (int $i) => $out[$i], $idx);
        }

        return $out;
    }

    /**
     * Stable sort by an item field; "-field" sorts descending. Numeric values
     * (decimal comma allowed, e.g. "318,5") compare numerically.
     *
     * @param  list<CollectionItem> $items
     * @return list<CollectionItem>
     */
    private function sortItems(array $items, string $sortBy): array
    {
        $desc = str_starts_with($sortBy, '-');
        $key  = strtolower(ltrim($sortBy, '-+'));

        $value = function (CollectionItem $item) use ($key): mixed {
            $raw = match ($key) {
                'title' => $item->title,
                'slug'  => $item->slug,
                'sort'  => $item->sort,
                default => $item->extra[$key] ?? null,
            };
            if ($raw === null || $raw === '') {
                return null;
            }
            $num = str_replace([' ', ','], ['', '.'], (string) $raw);
            return is_numeric($num) ? (float) $num : mb_strtolower((string) $raw);
        };

        $keyed = [];
        foreach ($items as $i => $item) {
            $keyed[] = [$value($item), $i, $item];
        }
        usort($keyed, function (array $a, array $b) use ($desc): int {
            // Items without a value always go last, in their original order.
            if ($a[0] === null || $b[0] === null) {
                return [$a[0] === null, $a[1]] <=> [$b[0] === null, $b[1]];
            }
            $cmp = $a[0] <=> $b[0];
            return ($desc ? -$cmp : $cmp) ?: $a[1] <=> $b[1];
        });

        return array_column($keyed, 2);
    }

    private function label(string $template, CollectionItem $item): string
    {
        $label = preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', function (array $m) use ($item): string {
            $key = strtolower($m[1]);
            return match ($key) {
                'title' => $item->title,
                'slug'  => $item->slug,
                default => (string) ($item->extra[$key] ?? ''),
            };
        }, $template);

        $label = trim((string) $label);
        return $label !== '' ? $label : $item->title;
    }
}

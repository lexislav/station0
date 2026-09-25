<?php

declare(strict_types=1);

namespace Station0\Support;

/**
 * Schema field-definition helpers shared by blocks, page fields and renderers.
 */
final class FieldSchema
{
    /**
     * Convert dict-form schema fields ({name: {...}}) to list form with `name`
     * injected, recursing `item:` into `item_fields:` for list fields.
     *
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $def) {
            $field         = is_array($def) ? $def : [];
            $field['name'] = is_string($name) ? $name : (string) ($field['name'] ?? '');
            if (isset($field['item']) && is_array($field['item'])) {
                $field['item_fields'] = self::normalize($field['item']);
                unset($field['item']);
            }
            $out[] = $field;
        }
        return $out;
    }
}

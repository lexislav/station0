<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Page-level fields: schema-declared values stored in a page's front matter
 * (in Page::$extra), declared per template via `fields:` in the template's
 * `<template>.blocks.yaml` manifest ({@see TemplateBlocks::fields()}).
 *
 * Values are keyed by the schema field name. Front-matter keys are
 * case-insensitive (Page::$extra holds them lower-cased), so lookups and writes
 * go through the lower-cased name. Only declared fields are ever read or
 * written; any other extra front-matter keys are left untouched.
 *
 * Supported types: text, textarea, image, file, number, select, boolean,
 * color, list (with `item:` sub-fields).
 */
final class PageFields
{
    public function __construct(
        private readonly TemplateBlocks $templates,
        private readonly MediaService $media,
    ) {}

    /** @return list<array<string, mixed>> Field definitions for $template. */
    public function definitions(string $template): array
    {
        return $this->templates->fields($template);
    }

    /**
     * Typed field values of $page, keyed by field name — what the admin form
     * edits. A page that has not been saved yet (no file) starts from the
     * schema defaults; a saved page reports what it stores (missing ⇒ empty).
     *
     * @param list<array<string, mixed>>|null $fields  Defaults to the page template's fields.
     * @return array<string, mixed>
     */
    public function values(Page $page, ?array $fields = null): array
    {
        $fields ??= $this->definitions($page->template);
        $isNew    = $page->filePath === '';

        $out = [];
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $key  = strtolower($name);
            if (array_key_exists($key, $page->extra)) {
                $out[$name] = self::cast($field, $page->extra[$key]);
            } else {
                $out[$name] = $isNew ? BlockRegistry::fieldDefault($field) : self::cast($field, null);
            }
        }
        return $out;
    }

    /**
     * Field values ready for a public Twig template: typed, with `image` /
     * `file` references (also inside list items) resolved to /media URLs.
     *
     * @return array<string, mixed>
     */
    public function resolved(Page $page): array
    {
        $fields = $this->definitions($page->template);
        if ($fields === []) {
            return [];
        }
        return $this->media->resolveFieldRefs($this->values($page, $fields), $fields, $page->urlPath);
    }

    /**
     * Write submitted values into $page->extra. Only declared fields present in
     * $input are touched; each value is sanitized to its field type.
     *
     * @param array<string, mixed>            $input   Field name ⇒ submitted value.
     * @param list<array<string, mixed>>|null $fields  Defaults to the page template's fields.
     */
    public function apply(Page $page, array $input, ?array $fields = null): void
    {
        $fields ??= $this->definitions($page->template);
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $page->extra[strtolower($name)] = self::sanitize($field, $input[$name]);
        }
    }

    // ─── Type handling ───

    /**
     * Stored (front matter) or submitted value → the storable form.
     *
     * @param array<string, mixed> $field
     */
    private static function sanitize(array $field, mixed $value): mixed
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'list') {
            return self::sanitizeList($field, $value);
        }
        if ($type === 'boolean') {
            return self::toBool($value);
        }
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));

        return match ($type) {
            'number'   => is_numeric(trim($value)) ? trim($value) : '',
            'textarea' => rtrim($value),
            // Single-line types: a newline has no business here.
            default    => trim(str_replace("\n", ' ', $value)),
        };
    }

    /**
     * @param array<string, mixed> $field
     * @return list<mixed>
     */
    private static function sanitizeList(array $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $itemFields = is_array($field['item_fields'] ?? null) ? $field['item_fields'] : [];

        $out = [];
        foreach (array_values($value) as $item) {
            if ($itemFields === []) {
                // Plain list of scalars.
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $out[] = trim((string) $item);
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($itemFields as $sub) {
                $subName       = (string) ($sub['name'] ?? '');
                if ($subName === '') {
                    continue;
                }
                $row[$subName] = self::sanitize($sub, $item[$subName] ?? null);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Stored value → typed value for templates / the admin form.
     *
     * @param array<string, mixed> $field
     */
    private static function cast(array $field, mixed $value): mixed
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'list') {
            $items      = is_array($value) ? array_values($value) : [];
            $itemFields = is_array($field['item_fields'] ?? null) ? $field['item_fields'] : [];
            if ($itemFields === []) {
                return $items;
            }
            return array_map(static function (mixed $item) use ($itemFields): array {
                $item = is_array($item) ? $item : [];
                $row  = [];
                foreach ($itemFields as $sub) {
                    $subName       = (string) ($sub['name'] ?? '');
                    $row[$subName] = self::cast($sub, $item[$subName] ?? null);
                }
                return $row;
            }, $items);
        }
        if ($type === 'boolean') {
            return self::toBool($value);
        }
        if ($type === 'number') {
            if (is_int($value) || is_float($value)) {
                return $value;
            }
            $value = trim((string) (is_scalar($value) ? $value : ''));
            if (!is_numeric($value)) {
                return null;
            }
            return str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value;
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var(is_scalar($value) ? $value : false, FILTER_VALIDATE_BOOLEAN);
    }
}

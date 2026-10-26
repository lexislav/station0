<?php

declare(strict_types=1);

namespace Station0\Support;

/**
 * `{key}` interpolation for labels and computed titles
 * (`option_label`, a collection's `title: "{name} {surname}"`).
 *
 * Keys are case-insensitive, values must be scalars (anything else and a
 * missing key count as empty). Whitespace is collapsed and the result
 * trimmed. When a placeholder came out empty, the separators it leaves
 * behind are cleaned up too: `{brand} - {model}` without a model is
 * `Škoda`, not `Škoda -`; `{a}, {b}, {c}` without b is `A, C`; an empty
 * `({year})` disappears. Text between filled placeholders is never touched.
 *
 * The admin item form mirrors this in JS (collections/form.twig) — keep both
 * in step.
 */
final class Placeholders
{
    /** Characters treated as separators when a placeholder is empty. */
    public const SEPARATORS = '-–—,;:|/·';

    private const PATTERN = '/\{([a-zA-Z0-9_-]+)\}/';

    /** @param array<string, mixed> $values */
    public static function fill(string $template, array $values): string
    {
        $values = array_change_key_case($values, CASE_LOWER);
        $empty  = false;

        $out = (string) preg_replace_callback(self::PATTERN, function (array $m) use ($values, &$empty): string {
            $v = $values[strtolower($m[1])] ?? '';
            $v = is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? trim((string) $v) : '');
            if ($v === '') {
                $empty = true;
            }
            return $v;
        }, $template);

        $out = (string) preg_replace('/\s+/u', ' ', $out);
        return $empty ? self::cleanup($out) : trim($out);
    }

    /**
     * Field names a template refers to, lower-cased, in order, unique.
     *
     * @return list<string>
     */
    public static function names(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $m);
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    private static function cleanup(string $s): string
    {
        $sep = preg_quote(self::SEPARATORS, '/');
        // Brackets left empty: "Škoda ()" → "Škoda".
        $s = (string) preg_replace('/\(\s*\)|\[\s*\]/u', '', $s);
        // A separator run with nothing between: "A - - C" / "A, , C" → keep the first.
        $s = (string) preg_replace('/(\s*[' . $sep . '])(?:\s*[' . $sep . '])+/u', '$1', $s);
        // Separators (and space) at either end: "Škoda -" / ", A".
        $s = (string) preg_replace('/^[\s' . $sep . ']+|[\s' . $sep . ']+$/u', '', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Kirby-style front matter codec shared by pages and collection items.
 *
 *   Title: My page
 *   Published: true
 *   Summary: |
 *     First line
 *     Second line
 *   Features:
 *     - title: Fast
 *       text: Really fast
 *   ---
 *
 *   Body…
 *
 * Simple values stay one `Key: value` line, read verbatim (no YAML typing, so
 * titles with colons or quotes keep working). A key whose value continues on
 * indented lines is a YAML block — used for multi-line strings (literal `|`)
 * and structured values (lists / maps). Keys are lower-cased on parse.
 */
final class FrontMatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string}  [meta, body]
     */
    public static function parse(string $raw): array
    {
        $raw   = str_replace("\r\n", "\n", $raw);
        $lines = explode("\n", $raw);

        $sepIdx = null;
        foreach ($lines as $i => $line) {
            if (rtrim($line) === '---') {
                $sepIdx = $i;
                break;
            }
        }
        if ($sepIdx === null) {
            return [[], $raw];
        }

        $head = array_slice($lines, 0, $sepIdx);
        $meta = [];
        $n    = count($head);
        for ($i = 0; $i < $n; $i++) {
            if (!preg_match('/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*)$/', $head[$i], $m)) {
                continue;
            }
            $key   = $m[1];
            $value = rtrim($m[2]);

            // Gather the indented continuation block (blank lines allowed inside).
            $block = [];
            $last  = $i;
            for ($j = $i + 1; $j < $n; $j++) {
                $line = $head[$j];
                if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                    $last = $j;
                } elseif (trim($line) !== '') {
                    break;
                }
            }
            if ($last > $i) {
                $block = array_slice($head, $i + 1, $last - $i);
                $i     = $last;
            }

            $meta[strtolower($key)] = $block === []
                ? $value
                : self::parseBlock($key, $value, $block);
        }

        $body = ltrim(implode("\n", array_slice($lines, $sepIdx + 1)), "\n ");
        return [$meta, $body];
    }

    /**
     * Serialize ordered fields + body. Null / '' / [] values are omitted.
     * Scalars go on one line; multi-line strings and arrays become YAML blocks.
     *
     * @param array<string, mixed> $fields  Keys as they should appear in the file.
     */
    public static function serialize(array $fields, string $body): string
    {
        $lines = [];
        foreach ($fields as $key => $val) {
            if ($val === null || $val === '' || $val === []) {
                continue;
            }
            if (is_bool($val)) {
                $val = $val ? 'true' : 'false';
            }
            if (is_string($val)) {
                $val = str_replace(["\r\n", "\r"], "\n", $val);
            }
            if (is_array($val) || (is_string($val) && str_contains($val, "\n"))) {
                $lines[] = rtrim(Yaml::dump([$key => $val], 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING), "\n");
                continue;
            }
            $lines[] = $key . ': ' . $val;
        }

        return implode("\n", $lines) . "\n---\n\n" . rtrim($body) . "\n";
    }

    /** @param list<string> $block */
    private static function parseBlock(string $key, string $value, array $block): mixed
    {
        $yaml = $key . ':' . ($value !== '' ? ' ' . $value : '') . "\n" . implode("\n", $block) . "\n";
        try {
            $data = Yaml::parse($yaml);
            if (is_array($data) && array_key_exists($key, $data)) {
                return $data[$key];
            }
        } catch (ParseException) {
            // fall through — keep the raw text rather than losing content
        }
        return trim($value . "\n" . implode("\n", $block));
    }
}

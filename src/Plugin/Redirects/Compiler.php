<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

/**
 * Normalized rules → the compiled rule set (a plain array, written to
 * `writable/cache/redirects.php` and served from opcache):
 *
 *   rules        index ⇒ rule (+ `names` of a pattern)
 *   exact        path ⇒ index        exactCi  lower-case path ⇒ index
 *   regex        one combined (*MARK) regex for patterns + regexes, or null
 *   force*       the same three for `force` rules; hasForce
 *   byTarget     static local target ⇒ indexes        (admin: incoming rules)
 *   bySubtree    auto subtree target prefix ⇒ indexes  (admin: "via /old/…")
 *   byFrom       exact source ⇒ indexes                (admin: force / shadowed)
 *
 * First match wins in file order; exact rules before patterns / regexes.
 */
final class Compiler
{
    public const VERSION = 1;

    /** @param list<array> $rules normalized, valid rules in file order */
    public static function compile(array $rules): array
    {
        $set = [
            'v' => self::VERSION, 'rules' => [],
            'exact' => [], 'exactCi' => [], 'regex' => null,
            'forceExact' => [], 'forceExactCi' => [], 'forceRegex' => null, 'hasForce' => false,
            'byTarget' => [], 'bySubtree' => [], 'byFrom' => [],
        ];
        $alternatives = ['' => [], 'force' => []];

        foreach (array_values($rules) as $i => $rule) {
            $rule['names'] = $rule['type'] === 'pattern' ? Rules::placeholders($rule['from']) : [];
            $set['rules'][$i] = $rule;
            $p = $rule['force'] ? 'force' : '';

            if ($rule['type'] === 'exact') {
                $exactKey = $p === 'force' ? 'forceExact' : 'exact';
                $map = $rule['caseless'] ? $exactKey . 'Ci' : $exactKey;
                $key = $rule['caseless'] ? strtolower($rule['from']) : $rule['from'];
                $set[$map][$key] ??= $i;
                $set['byFrom'][$rule['from']][] = $i;
            } else {
                $body = Rules::regexBody($rule);
                $alternatives[$p][] = ($rule['caseless'] ? '(?i:' . $body . ')' : $body) . '(*MARK:' . $i . ')';
            }
            $set['hasForce'] = $set['hasForce'] || $rule['force'];

            if (($target = Rules::staticTarget($rule)) !== null) {
                $set['byTarget'][$target][] = $i;
            }
            if ($rule['auto'] !== null && ($subtree = Rules::subtree($rule)) !== null) {
                $set['bySubtree'][$subtree[1]][] = $i;
            }
        }
        foreach (['' => 'regex', 'force' => 'forceRegex'] as $p => $key) {
            if ($alternatives[$p] !== []) {
                // Branch reset: every alternative numbers its groups from 1.
                $set[$key] = Rules::delimit('^(?|' . implode('|', $alternatives[$p]) . ')$');
            }
        }
        return $set;
    }

    /**
     * Only what the per-request force check needs: the force rules and their
     * maps. Kept in its own small file so a normal request never loads the
     * full rule set.
     */
    public static function forceSubset(array $set): array
    {
        $force = array_filter($set['rules'], fn (array $rule) => $rule['force']);
        return ['rules' => $force, 'exact' => [], 'exactCi' => [], 'regex' => null,
            'byTarget' => [], 'bySubtree' => [], 'byFrom' => []]
            + array_intersect_key($set, array_flip(['v', 'forceExact', 'forceExactCi', 'forceRegex', 'hasForce']));
    }

    /** The force-only file beside the compiled set: redirects.php → redirects.force.php. */
    public static function forceFile(string $file): string
    {
        return (string) preg_replace('/\.php$/', '.force.php', $file);
    }

    /** Write the compiled set and its force subset as PHP files (tmp + rename, opcache refreshed). */
    public static function write(array $set, string $file): void
    {
        @mkdir(dirname($file), 0775, true);
        // Force file first: a reader that sees the new main file also gets the matching force file.
        foreach ([self::forceFile($file) => self::forceSubset($set), $file => $set] as $path => $data) {
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, "<?php\n// Compiled from _redirects.yaml — do not edit; regenerated automatically.\nreturn "
                . var_export($data, true) . ";\n");
            rename($tmp, $path);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        }
    }
}

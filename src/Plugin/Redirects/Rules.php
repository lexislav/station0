<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

/**
 * One redirect rule: normalizing what `_redirects.yaml` / the admin give,
 * validating it, and path helpers shared by the compiler and matcher.
 *
 * A normalized rule:
 *   id, type (exact|pattern|regex), from, to (?string), status (301|302|307|308|410),
 *   force, caseless, query (keep|drop), count, auto (?Y-m-d), note (?string)
 */
final class Rules
{
    public const STATUSES  = [301, 302, 307, 308, 410];
    public const MAX_HOPS  = 10;
    /** `{name}` = one segment, `{name*}` = zero or more segments. */
    public const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)(\*)?\}/';

    /** Leading slash, no repeated slashes, no trailing slash (except "/"), no query / fragment. */
    public static function normalizePath(string $path): string
    {
        $path = preg_replace('/[?#].*$/s', '', trim($path)) ?? '';
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function id(string $type, string $from): string
    {
        return substr(sha1($type . ' ' . $from), 0, 10);
    }

    /**
     * Raw rule (YAML map or form input) → normalized rule. Throws
     * \InvalidArgumentException with a readable message for invalid input
     * that cannot be normalized; validate() checks the rest.
     */
    public static function normalize(array $raw): array
    {
        $from = trim((string) ($raw['from'] ?? ''));
        if ($from === '') {
            throw new \InvalidArgumentException('A rule needs "from".');
        }
        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        if ($type === '') {
            $type = preg_match(self::PLACEHOLDER, $from) ? 'pattern' : 'exact';
        }
        if (!in_array($type, ['exact', 'pattern', 'regex'], true)) {
            throw new \InvalidArgumentException("Unknown rule type \"{$type}\" (exact, pattern, regex).");
        }
        if ($type !== 'regex') {
            $from = self::normalizePath($from);
        }
        $status = (int) ($raw['status'] ?? 301);
        $to     = trim((string) ($raw['to'] ?? ''));
        $auto   = $raw['auto'] ?? null;
        if (is_int($auto)) {
            $auto = date('Y-m-d', $auto);   // unquoted YAML date
        }

        return [
            'id'       => self::id($type, $from),
            'type'     => $type,
            'from'     => $from,
            'to'       => $status === 410 || $to === '' ? null : $to,
            'status'   => $status,
            'force'    => filter_var($raw['force'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'caseless' => filter_var($raw['caseless'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'query'    => ($raw['query'] ?? 'keep') === 'drop' ? 'drop' : 'keep',
            'count'    => filter_var($raw['count'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'auto'     => $auto !== null && $auto !== '' && $auto !== false ? (string) $auto : null,
            'note'     => isset($raw['note']) && trim((string) $raw['note']) !== '' ? trim((string) $raw['note']) : null,
        ];
    }

    /** Normalized rule → the YAML map (defaults left out). */
    public static function toYaml(array $rule): array
    {
        $out = ['from' => $rule['from']];
        if ($rule['type'] === 'regex') {
            $out['type'] = 'regex';
        }
        if ($rule['to'] !== null) {
            $out['to'] = $rule['to'];
        }
        if ($rule['status'] !== 301) {
            $out['status'] = $rule['status'];
        }
        foreach (['force', 'caseless', 'count'] as $flag) {
            if ($rule[$flag]) {
                $out[$flag] = true;
            }
        }
        if ($rule['query'] === 'drop') {
            $out['query'] = 'drop';
        }
        if ($rule['auto'] !== null) {
            $out['auto'] = $rule['auto'];
        }
        if ($rule['note'] !== null) {
            $out['note'] = $rule['note'];
        }
        return $out;
    }

    /**
     * Problems with a normalized rule, as readable messages ([] = valid).
     *
     * @param list<string> $reserved path prefixes a rule may not redirect from
     */
    public static function validate(array $rule, array $reserved = []): array
    {
        $errors = [];
        if (!in_array($rule['status'], self::STATUSES, true)) {
            $errors[] = 'Status must be one of ' . implode(', ', self::STATUSES) . '.';
        }
        if ($rule['status'] !== 410 && $rule['to'] === null) {
            $errors[] = 'A redirect needs "to" (or status 410).';
        }

        // Source
        if ($rule['type'] === 'regex') {
            $from = $rule['from'];
            if (!str_starts_with($from, '^') || !str_ends_with($from, '$')) {
                $errors[] = 'A regex must be anchored: ^…$.';
            } elseif (preg_match('/\(\?(?:P?<[A-Za-z_]|\')/', $from)) {
                $errors[] = 'Named groups are not supported in a regex — use (…) and $1, $2 ….';
            } else {
                set_error_handler(static fn () => true);
                $ok = @preg_match(self::delimit($from), '') !== false;
                restore_error_handler();
                if (!$ok) {
                    $errors[] = 'Invalid regex: ' . preg_last_error_msg() . '.';
                }
            }
        } elseif ($rule['type'] === 'pattern') {
            // (A query string in an exact / pattern "from" was dropped by normalize(): matching ignores it.)
            $names = self::placeholders($rule['from']);
            if (count($names) !== count(array_unique($names))) {
                $errors[] = 'Each placeholder name may appear once in "from".';
            }
        }
        $sample = self::sampleSource($rule);
        if ($sample === null || !str_starts_with($sample, '/') || str_starts_with($sample, '//')) {
            $errors[] = '"from" must be a local path starting with /.';
        } else {
            foreach ($reserved as $prefix) {
                $prefix = rtrim($prefix, '/');
                if ($prefix !== '' && ($sample === $prefix || str_starts_with($sample, $prefix . '/'))) {
                    $errors[] = "\"from\" cannot be under {$prefix}.";
                }
            }
        }

        // Target
        if ($rule['to'] !== null) {
            $to = $rule['to'];
            if (preg_match('#^https?://#i', $to)) {
                if (!preg_match('#^https?://[^/?\#{}$\s]+(?:[/?\#].*)?$#i', $to)) {
                    $errors[] = 'Placeholders may only be used in the path or query of an absolute "to", never in its host.';
                }
            } elseif (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
                $errors[] = '"to" must be a local path (/…) or an http(s) URL.';
            }
            $known = $rule['type'] === 'pattern' ? self::placeholders($rule['from']) : [];
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $to, $m);
            foreach ($m[1] as $name) {
                if (!in_array($name, $known, true)) {
                    $errors[] = "\"to\" uses {{$name}}, which \"from\" does not define.";
                }
            }
            if ($rule['type'] !== 'regex' && preg_match('/\$\d/', $to)) {
                $errors[] = '$1, $2 … only work with type: regex.';
            }
        }
        return $errors;
    }

    /** @return list<string> placeholder names of a pattern, in order */
    public static function placeholders(string $from): array
    {
        preg_match_all(self::PLACEHOLDER, $from, $m);
        return $m[1];
    }

    /** A path the rule matches, for reserved-prefix and loop checks (null = unknown). */
    public static function sampleSource(array $rule): ?string
    {
        return match ($rule['type']) {
            'exact'   => $rule['from'],
            'pattern' => self::normalizePath((string) preg_replace(self::PLACEHOLDER, 'x', $rule['from'])),
            // ^/p/(\d+)$ → /p/ — the literal prefix up to the first metacharacter
            'regex'   => '/' . ltrim((string) preg_replace('/[\\\\\[\](){}.*+?|$].*$/s', '', substr($rule['from'], 1)), '/'),
        };
    }

    /** The regex body of a rule, without anchors / delimiters. */
    public static function regexBody(array $rule): string
    {
        if ($rule['type'] === 'regex') {
            return substr($rule['from'], 1, -1);
        }
        $out  = '';
        $last = 0;
        preg_match_all(self::PLACEHOLDER, $rule['from'], $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $match) {
            $literal = substr($rule['from'], $last, $match[0][1] - $last);
            if (!empty($match[2][0])) {
                // {path*}: zero or more segments; "/docs/{path*}" also matches "/docs".
                if (str_ends_with($literal, '/')) {
                    $out .= preg_quote(substr($literal, 0, -1), "\x01") . '(?:/(.*))?';
                } else {
                    $out .= preg_quote($literal, "\x01") . '(.*)';
                }
            } else {
                $out .= preg_quote($literal, "\x01") . '([^/]+)';
            }
            $last = $match[0][1] + strlen($match[0][0]);
        }
        return $out . preg_quote(substr($rule['from'], $last), "\x01");
    }

    public static function delimit(string $regex, string $flags = ''): string
    {
        return "\x01" . $regex . "\x01" . $flags;
    }

    /**
     * The target of a matched rule: placeholders / $n substituted (URL-encoded,
     * path / query only), local paths normalized, the request query appended
     * unless `query: drop`.
     *
     * @param array<int, string> $groups capture values by group number (1…)
     */
    public static function target(array $rule, array $groups, string $query = ''): string
    {
        $to     = (string) $rule['to'];
        $prefix = '';
        if (preg_match('#^(https?://[^/?\#]+)(.*)$#is', $to, $m)) {
            [$prefix, $to] = [$m[1], $m[2]];
        }
        [$path, $targetQuery] = array_pad(explode('?', $to, 2), 2, null);

        $values = [];
        if ($rule['type'] === 'pattern') {
            foreach (self::placeholders($rule['from']) as $i => $name) {
                $values['{' . $name . '}'] = (string) ($groups[$i + 1] ?? '');
            }
        } else {
            for ($n = 9; $n >= 1; $n--) {   // $10 is not supported; $1 must not eat $1x
                $values['$' . $n] = (string) ($groups[$n] ?? '');
            }
        }
        $encodePath  = static fn (string $v): string => implode('/', array_map('rawurlencode', explode('/', $v)));
        $path        = strtr($path, array_map($encodePath, $values));
        $targetQuery = $targetQuery !== null ? strtr($targetQuery, array_map('rawurlencode', $values)) : null;

        if ($prefix === '') {
            $path = self::normalizePath($path);   // also turns //evil.com into /evil.com
        }
        $queries = array_filter([$targetQuery, $rule['query'] === 'drop' ? '' : $query], fn ($q) => $q !== null && $q !== '');
        return $prefix . $path . ($queries !== [] ? '?' . implode('&', $queries) : '');
    }

    /** The local path a static target points to (no placeholders, no host), else null. */
    public static function staticTarget(array $rule): ?string
    {
        $to = $rule['to'];
        if ($to === null || !str_starts_with($to, '/') || preg_match('/\{|\$\d/', $to)) {
            return null;
        }
        return self::normalizePath($to);
    }

    /**
     * For an auto subtree rule (`/old/{path*}` → `/new/{path}`): [old, new].
     *
     * @return array{string, string}|null
     */
    public static function subtree(array $rule): ?array
    {
        if ($rule['type'] !== 'pattern' || $rule['to'] === null
            || !preg_match('#^(/.*?)/\{([A-Za-z_][A-Za-z0-9_]*)\*\}$#', $rule['from'], $f)
            || !preg_match('#^(/.*?)/\{' . preg_quote($f[2], '#') . '\}$#', $rule['to'], $t)
            || preg_match(self::PLACEHOLDER, $f[1] . $t[1])) {
            return null;
        }
        return [$f[1], $t[1]];
    }
}

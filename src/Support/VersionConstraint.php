<?php

declare(strict_types=1);

namespace Station0\Support;

/**
 * The subset of Composer version constraints plugins use to state the
 * station0 versions they support: `^0.10`, `~1.2`, `>=0.10 <0.12`,
 * `0.10.*`, `1.2.3`, alternatives with `||`. composer/semver is not a
 * runtime dependency, so this is a small re-implementation.
 */
final class VersionConstraint
{
    /** A tagged release version (`0.10.0`, `v1.2`) — not `dev-main` etc. */
    public static function isRelease(string $version): bool
    {
        return (bool) preg_match('/^v?\d+(\.\d+){0,3}$/', trim($version));
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $parts = preg_split('/\s*,\s*|\s+/', trim($alternative)) ?: [];
            $ok = $parts !== [];
            foreach ($parts as $part) {
                if (!self::matchesOne($version, $part)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    private static function matchesOne(string $version, string $part): bool
    {
        if ($part === '*' || $part === '') {
            return true;
        }
        if (!preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)?\s*v?(\d+(?:\.(?:\d+|\*|x))*)$/', $part, $m)) {
            return false;
        }
        $op  = $m[1];
        $raw = $m[2];

        if (preg_match('/\.(\*|x)$/', $raw)) {
            // 0.10.* ⇒ >=0.10.0 <0.11.0
            $fixed = explode('.', preg_replace('/\.(\*|x)$/', '', $raw));
            $lower = self::normalize(implode('.', $fixed));
            $fixed[count($fixed) - 1] = (string) ((int) end($fixed) + 1);
            return self::cmp($version, $lower) >= 0 && self::cmp($version, self::normalize(implode('.', $fixed))) < 0;
        }

        $segments = explode('.', $raw);
        $target   = self::normalize($raw);
        switch ($op) {
            case '^':
                // First non-zero segment is fixed: ^1.2 <2.0, ^0.10 <0.11, ^0.0.3 <0.0.4.
                $upper = array_map('intval', array_pad($segments, 3, '0'));
                $i = 0;
                while ($i < count($segments) - 1 && $upper[$i] === 0) {
                    $i++;
                }
                $upper[$i]++;
                for ($j = $i + 1; $j < 3; $j++) {
                    $upper[$j] = 0;
                }
                return self::cmp($version, $target) >= 0 && self::cmp($version, implode('.', $upper)) < 0;
            case '~':
                // ~1.2 <2.0, ~1.2.3 <1.3.0
                $upper = array_map('intval', array_pad($segments, 3, '0'));
                $i = max(0, count($segments) - 2);
                $upper[$i]++;
                for ($j = $i + 1; $j < 3; $j++) {
                    $upper[$j] = 0;
                }
                return self::cmp($version, $target) >= 0 && self::cmp($version, implode('.', $upper)) < 0;
            case '>=': return self::cmp($version, $target) >= 0;
            case '<=': return self::cmp($version, $target) <= 0;
            case '>':  return self::cmp($version, $target) > 0;
            case '<':  return self::cmp($version, $target) < 0;
            case '!=': return self::cmp($version, $target) !== 0;
            default:   return self::cmp($version, $target) === 0;
        }
    }

    private static function normalize(string $version): string
    {
        $parts = array_map('intval', explode('.', ltrim(trim($version), 'v')));
        return implode('.', array_slice(array_pad($parts, 3, 0), 0, 3));
    }

    private static function cmp(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }
}

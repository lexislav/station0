<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

/**
 * A likely target for a logged 404 (admin 404 log): a live page with the
 * same last segment (moved), else one whose path is a few typos away, else
 * the nearest live ancestor. Never the homepage — redirecting misses there
 * is a soft 404.
 */
final class Suggester
{
    /** @var array<string, list<string>> lower-cased last segment ⇒ live paths */
    private array $bySlug = [];

    /** @var array<string, true> */
    private array $live = [];

    /** @param list<string> $livePaths URL paths of the live pages */
    public function __construct(array $livePaths)
    {
        foreach ($livePaths as $path) {
            if ($path === '/' || $path === '') {
                continue;
            }
            $this->live[$path] = true;
            $this->bySlug[self::slug($path)][] = $path;
        }
    }

    /** @return array{to: string, reason: 'slug'|'similar'|'parent'}|null */
    public function suggest(string $path): ?array
    {
        $path = Rules::normalizePath($path);
        if ($path === '/' || isset($this->live[$path])) {
            return null;
        }

        $sameSlug = array_values(array_filter($this->bySlug[self::slug($path)] ?? [], fn ($p) => $p !== $path));
        if ($sameSlug !== []) {
            return ['to' => $this->closest($path, $sameSlug), 'reason' => 'slug'];
        }

        $lower = strtolower($path);
        $best  = null;
        $bestDistance = max(2, intdiv(strlen($lower), 5)) + 1;
        foreach (array_keys($this->live) as $candidate) {
            $distance = levenshtein($lower, strtolower($candidate));
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }
        if ($best !== null) {
            return ['to' => $best, 'reason' => 'similar'];
        }

        for ($parent = dirname($path); $parent !== '/' && $parent !== '.' && $parent !== ''; $parent = dirname($parent)) {
            if (isset($this->live[$parent])) {
                return ['to' => $parent, 'reason' => 'parent'];
            }
        }
        return null;
    }

    /** @param non-empty-list<string> $candidates */
    private function closest(string $path, array $candidates): string
    {
        usort($candidates, fn ($a, $b) => levenshtein($path, $a) <=> levenshtein($path, $b) ?: strcmp($a, $b));
        return $candidates[0];
    }

    /** Last segment, lower-cased, without a legacy file extension (`about.html` ⇒ `about`). */
    private static function slug(string $path): string
    {
        $last = strtolower(basename($path));
        return (string) preg_replace('/\.(html?|php|aspx?|jsp)$/', '', $last);
    }
}

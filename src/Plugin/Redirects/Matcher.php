<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

/**
 * Looks a path up in a compiled rule set: one isset() per exact map, then
 * at most one preg_match for all patterns + regexes together.
 */
final class Matcher
{
    /** Backtracking budget while matching, so a bad regex rule cannot hang a request. */
    public const BACKTRACK_LIMIT = 100000;

    public function __construct(private readonly array $set) {}

    public function set(): array
    {
        return $this->set;
    }

    public function hasForce(): bool
    {
        return (bool) ($this->set['hasForce'] ?? false);
    }

    /**
     * @param bool    $force      true = only `force` rules, false = only fallback rules
     * @param string  $lang       request language: its `lang` rules are tried first
     * @param ?string $publicPath what `lang` rules match (the requested public path); default $path
     * @return array{rule: array, index: int, status: int, location: ?string}|null
     */
    public function match(string $path, string $query = '', bool $force = false, string $lang = '', ?string $publicPath = null): ?array
    {
        if (!$force && $lang !== '' && isset($this->set['lang'][$lang])) {
            $hit = $this->lookup($this->set['lang'][$lang], Rules::normalizePath($publicPath ?? $path), $query);
            if ($hit !== null) {
                return $hit;
            }
        }
        $maps = $force
            ? ['exact' => $this->set['forceExact'] ?? [], 'exactCi' => $this->set['forceExactCi'] ?? [], 'regex' => $this->set['forceRegex'] ?? null]
            : ['exact' => $this->set['exact'] ?? [], 'exactCi' => $this->set['exactCi'] ?? [], 'regex' => $this->set['regex'] ?? null];
        return $this->lookup($maps, Rules::normalizePath($path), $query);
    }

    /** @param array{exact: array, exactCi: array, regex: ?string} $maps */
    private function lookup(array $maps, string $path, string $query): ?array
    {
        $candidates = array_filter([
            $maps['exact'][$path] ?? null,
            $maps['exactCi'][strtolower($path)] ?? null,
        ], fn ($i) => $i !== null);
        if ($candidates !== []) {
            return $this->result(min($candidates), [], $query);
        }

        if (($pattern = $maps['regex']) !== null) {
            $limit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
            $ok = preg_match($pattern, $path, $m);
            ini_set('pcre.backtrack_limit', (string) $limit);
            if ($ok === 1 && isset($m['MARK'])) {
                $groups = [];
                foreach ($m as $k => $v) {
                    if (is_int($k) && $k > 0) {
                        $groups[$k] = $v;
                    }
                }
                return $this->result((int) $m['MARK'], $groups, $query);
            }
        }
        return null;
    }

    /**
     * Fallback and force rules — what a request to $path ends up at (admin
     * "Test a URL"). With $lang, $path is a public path in that language.
     */
    public function test(string $path, string $query = '', string $lang = ''): ?array
    {
        return $this->match($path, $query, true) ?? $this->match($path, $query, false, $lang);
    }

    // ── Admin index lookups ──────────────────────────────────────────────

    /**
     * Rules leading to a page: static targets and auto subtree rules covering it.
     *
     * @return list<array{rule: array, via: ?string}>
     */
    public function incoming(string $path): array
    {
        $path = Rules::normalizePath($path);
        $out  = [];
        foreach ($this->set['byTarget'][$path] ?? [] as $i) {
            $out[] = ['rule' => $this->set['rules'][$i], 'via' => null];
        }
        // Auto subtree rules whose target prefix is this page or an ancestor.
        for ($prefix = $path; $prefix !== '/' && $prefix !== ''; $prefix = rtrim(dirname($prefix), '/') ?: '/') {
            foreach ($this->set['bySubtree'][$prefix] ?? [] as $i) {
                $rule = $this->set['rules'][$i];
                $old  = Rules::subtree($rule)[0];
                $out[] = ['rule' => $rule, 'via' => $old . substr($path, strlen($prefix))];
            }
        }
        return $out;
    }

    /** @return list<array> force rules redirecting away from this exact path */
    public function blocking(string $path): array
    {
        $hit = $this->match($path, '', true);
        return $hit !== null ? [$hit['rule']] : [];
    }

    /** @return list<array> fallback rules from this exact path (a live page shadows them) */
    public function shadowed(string $path): array
    {
        $out = [];
        foreach ($this->set['byFrom'][Rules::normalizePath($path)] ?? [] as $i) {
            if (!$this->set['rules'][$i]['force']) {
                $out[] = $this->set['rules'][$i];
            }
        }
        return $out;
    }

    private function result(int $index, array $groups, string $query): array
    {
        $rule = $this->set['rules'][$index];
        return [
            'rule'     => $rule,
            'index'    => $index,
            'status'   => $rule['status'],
            'location' => $rule['status'] === 410 || $rule['to'] === null ? null : Rules::target($rule, $groups, $query),
        ];
    }
}

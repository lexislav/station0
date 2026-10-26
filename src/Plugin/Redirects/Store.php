<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The rules file `site/content/_redirects.yaml` and its compiled copy.
 *
 * Every write takes a lock, rewrites the YAML (tmp file + rename) and
 * recompiles, so the admin, console and auto rules never step on each other.
 * Hand edits are picked up on the next 404 (mtime check) or by
 * `console redirects:compile`.
 */
final class Store
{
    public const HEADER = "# Redirect rules — see docs/redirects.md in lexislav/station0.\n"
        . "# Rewritten by the admin and console: comments in this file are not kept.\n";

    private ?Matcher $matcher = null;
    private ?Matcher $forceMatcher = null;

    /** @var list<string>|null */
    private ?array $reservedList = null;

    /**
     * @param list<string>|\Closure(): list<string> $reserved path prefixes rules
     *        may not redirect from (a closure is called on first validation)
     */
    public function __construct(
        private readonly string $file,
        private readonly string $compiled,
        private readonly array|\Closure $reserved = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** The store of a site, from its config — e.g. in a site task: Store::forConfig($task->config()). */
    public static function forConfig(array $config, ?LoggerInterface $logger = null): self
    {
        return new self(
            $config['paths']['content'] . '/_redirects.yaml',
            $config['paths']['cache'] . '/redirects.php',
            array_values(array_filter([$config['adminPath'] ?? '/admin', '/thumb', $config['access']['loginPath'] ?? null])),
            $logger,
        );
    }

    /** @return list<string> */
    public function reserved(): array
    {
        return $this->reservedList ??= $this->reserved instanceof \Closure ? ($this->reserved)() : $this->reserved;
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Rules from the YAML, normalized. Invalid ones are left out of `rules`
     * and listed in `invalid` with their errors.
     *
     * `broken` holds the parse error when the file is not valid YAML.
     *
     * @return array{rules: list<array>, invalid: list<array{raw: mixed, errors: list<string>}>, broken: ?string}
     */
    public function load(): array
    {
        $rules = [];
        $invalid = [];
        $seen = [];
        $broken = null;
        foreach ($this->readRaw($broken) as $raw) {
            try {
                $rule = Rules::normalize(is_array($raw) ? $raw : []);
                $errors = Rules::validate($rule, $this->reserved());
                if (isset($seen[$rule['id']])) {
                    $errors[] = 'Duplicate source — the earlier rule wins.';
                }
            } catch (\InvalidArgumentException $e) {
                $errors = [$e->getMessage()];
            }
            if ($errors !== []) {
                $invalid[] = ['raw' => $raw, 'errors' => $errors];
                continue;
            }
            $seen[$rule['id']] = true;
            $rules[] = $rule;
        }
        return ['rules' => $rules, 'invalid' => $invalid, 'broken' => $broken];
    }

    /**
     * The matcher for the current rules. Recompiles when the YAML is newer
     * than the compiled file (one stat — this runs on the 404 path and in
     * the admin, never on a normal page request).
     */
    public function matcher(): Matcher
    {
        if ($this->matcher !== null) {
            return $this->matcher;
        }
        $set = is_file($this->compiled) ? @include $this->compiled : null;
        if (!is_array($set) || ($set['v'] ?? null) !== Compiler::VERSION
            || (is_file($this->file) && filemtime($this->file) > filemtime($this->compiled))) {
            $set = $this->compile();
        }
        return $this->matcher = new Matcher($set);
    }

    /**
     * Force rules only, from the small force file — for the per-request
     * check, so it trusts the compiled file without a stat. A hand edit of
     * force rules applies after the next 404 or `console redirects:compile`.
     */
    public function forceMatcher(): Matcher
    {
        if ($this->forceMatcher !== null) {
            return $this->forceMatcher;
        }
        $set = @include Compiler::forceFile($this->compiled);
        if (!is_array($set) || ($set['v'] ?? null) !== Compiler::VERSION) {
            $set = Compiler::forceSubset($this->compile());
        }
        return $this->forceMatcher = new Matcher($set);
    }

    /** Recompile from the YAML; invalid rules are logged and skipped. */
    public function compile(): array
    {
        $loaded = $this->load();
        foreach ($loaded['invalid'] as $bad) {
            $this->logger?->warning(sprintf('Redirect rule skipped (%s): %s',
                json_encode($bad['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), implode(' ', $bad['errors'])));
        }
        $set = Compiler::compile($loaded['rules']);
        Compiler::write($set, $this->compiled);
        $this->matcher = $this->forceMatcher = null;
        return $set;
    }

    public function find(string $id): ?array
    {
        foreach ($this->load()['rules'] as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Add a rule, or replace the rule $replaceId with it (same position).
     *
     * @throws \InvalidArgumentException with the validation errors, one per line
     */
    public function save(array $raw, ?string $replaceId = null): array
    {
        $rule   = Rules::normalize($raw);
        $errors = Rules::validate($rule, $this->reserved());
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }
        $this->mutate(function (array $rules) use ($rule, $replaceId): array {
            $position = null;
            foreach ($rules as $i => $existing) {
                if ($existing['id'] === $replaceId) {
                    $position = $i;
                } elseif ($existing['id'] === $rule['id']) {
                    throw new \InvalidArgumentException('A rule for this source already exists.');
                }
            }
            if ($position !== null) {
                $rules[$position] = $rule;
            } else {
                $rules[] = $rule;
            }
            if (($loop = self::loopIn($rules, $rule)) !== null) {
                throw new \InvalidArgumentException("This rule makes a redirect loop: {$loop}.");
            }
            return $rules;
        });
        return $rule;
    }

    public function remove(string $id): bool
    {
        $removed = false;
        $this->mutate(function (array $rules) use ($id, &$removed): array {
            $kept = array_values(array_filter($rules, fn (array $r) => $r['id'] !== $id));
            $removed = count($kept) !== count($rules);
            return $kept;
        });
        return $removed;
    }

    /**
     * A page moved from $old to $new (slug change or drag & drop): one
     * subtree rule `/old/{path*}` → `/new/{path}`, chains flattened, and rules
     * redirecting away from the page's new location removed (renaming back).
     */
    public function recordMove(string $old, string $new, string $note = ''): void
    {
        $old = Rules::normalizePath($old);
        $new = Rules::normalizePath($new);
        if ($old === $new || $old === '/' || $new === '/') {
            return;
        }
        $this->mutate(function (array $rules) use ($old, $new, $note): array {
            $under = fn (string $path, string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/');
            $out = [];
            foreach ($rules as $rule) {
                // The page lives at $new now: rules away from it are dead.
                if (($rule['type'] === 'exact' && $rule['from'] === $new && !$rule['force'])
                    || ($rule['auto'] !== null && (Rules::subtree($rule)[0] ?? null) === $new)) {
                    continue;
                }
                // Flatten: whatever pointed into the old location points to the new one.
                if ($rule['to'] !== null && str_starts_with($rule['to'], '/') && $under(strtok($rule['to'], '?'), $old)) {
                    $rule['to'] = $new . substr($rule['to'], strlen($old));
                }
                $subtree = Rules::subtree($rule);
                if (($rule['type'] === 'exact' && Rules::staticTarget($rule) === $rule['from'])
                    || ($subtree !== null && $subtree[0] === $subtree[1])) {
                    continue;   // became a self-redirect
                }
                $out[] = $rule;
            }
            $auto = Rules::normalize([
                'from' => $old . '/{path*}',
                'to'   => $new . '/{path}',
                'auto' => date('Y-m-d'),
                'note' => $note,
            ]);
            $out = array_values(array_filter($out, fn (array $r) => $r['id'] !== $auto['id']));
            $out[] = $auto;
            return $out;
        });
    }

    /**
     * Rows of [from, to, status] (CSV import). Each valid row is added;
     * existing sources are skipped.
     *
     * @param iterable<array> $rows
     * @return array{added: int, errors: list<string>}
     */
    public function import(iterable $rows): array
    {
        $added  = 0;
        $errors = [];
        $line   = 0;
        $this->mutate(function (array $rules) use ($rows, &$added, &$errors, &$line): array {
            $ids = array_flip(array_column($rules, 'id'));
            foreach ($rows as $row) {
                $line++;
                $row = array_map('trim', array_map('strval', array_values($row)));
                if ($row === [] || $row === [''] || ($line === 1 && strtolower($row[0]) === 'from')) {
                    continue;
                }
                try {
                    $rule = Rules::normalize(['from' => $row[0], 'to' => $row[1] ?? '', 'status' => ($row[2] ?? '') !== '' ? $row[2] : 301]);
                    $problems = Rules::validate($rule, $this->reserved());
                } catch (\InvalidArgumentException $e) {
                    $problems = [$e->getMessage()];
                }
                if ($problems === [] && isset($ids[$rule['id']])) {
                    $problems = ['source already has a rule'];
                }
                if ($problems !== []) {
                    $errors[] = "line {$line}: " . implode(' ', $problems);
                    continue;
                }
                $rules[] = $rule;
                $ids[$rule['id']] = true;
                $added++;
            }
            return $rules;
        });
        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Following the rules from $rule's source returns to an already visited
     * path within MAX_HOPS? Returns the chain, else null.
     */
    public static function loopIn(array $rules, array $rule): ?string
    {
        $matcher = new Matcher(Compiler::compile($rules));
        $path    = Rules::sampleSource($rule);
        $visited = [$path => true];
        $chain   = [$path];
        for ($hop = 0; $hop < Rules::MAX_HOPS; $hop++) {
            $hit = $matcher->test((string) $path);
            if ($hit === null || $hit['location'] === null || !str_starts_with($hit['location'], '/')) {
                return null;
            }
            $path    = Rules::normalizePath($hit['location']);
            $chain[] = $path;
            if (isset($visited[$path])) {
                return implode(' → ', $chain);
            }
            $visited[$path] = true;
        }
        return implode(' → ', $chain) . ' …';
    }

    /** @param callable(list<array>): list<array> $change */
    private function mutate(callable $change): void
    {
        @mkdir(dirname($this->compiled), 0775, true);
        $lock = fopen($this->compiled . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $current = $this->load();
            if ($current['broken'] !== null) {
                // Never overwrite a hand-edited file we could not read.
                throw new \RuntimeException('_redirects.yaml is not valid YAML — fix it first: ' . $current['broken']);
            }
            if ($current['invalid'] !== []) {
                // Keep hand-written rules the admin cannot parse, untouched, at the end.
                $this->logger?->warning('Redirect rules: ' . count($current['invalid']) . ' invalid rule(s) kept as written.');
            }
            $rules = $change($current['rules']);
            $yaml  = array_merge(array_map([Rules::class, 'toYaml'], $rules), array_column($current['invalid'], 'raw'));
            @mkdir(dirname($this->file), 0775, true);
            $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, self::HEADER . ($yaml === [] ? "[]\n" : Yaml::dump($yaml, 2, 2, Yaml::DUMP_COMPACT_NESTED_MAPPING)));
            rename($tmp, $this->file);
            $this->compile();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<mixed> */
    private function readRaw(?string &$broken = null): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        try {
            $data = Yaml::parse((string) file_get_contents($this->file));
        } catch (ParseException $e) {
            $broken = $e->getMessage();
            $this->logger?->error('Redirect rules: ' . $this->file . ' is not valid YAML: ' . $broken);
            return [];
        }
        return is_array($data) ? array_values($data) : [];
    }
}

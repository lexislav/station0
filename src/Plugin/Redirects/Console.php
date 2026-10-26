<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Station0\Plugin\Registrar;

/** `php vendor/bin/console redirects:*` commands. */
final class Console
{
    public static function register(Registrar $r): void
    {
        $r->command('redirects:list', function () use ($r): int {
            $loaded = $r->get(Store::class)->load();
            if ($loaded['broken'] !== null) {
                fwrite(STDERR, "_redirects.yaml není platný YAML: {$loaded['broken']}\n");
                return 1;
            }
            foreach ($loaded['rules'] as $rule) {
                echo self::line($rule) . "\n";
            }
            foreach ($loaded['invalid'] as $bad) {
                fwrite(STDERR, '[!] ' . json_encode($bad['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' — ' . implode(' ', $bad['errors']) . "\n");
            }
            echo count($loaded['rules']) . " pravidel.\n";
            return 0;
        }, 'Vypíše pravidla přesměrování');

        $r->command('redirects:test', function (array $args) use ($r): int {
            $url = $args[0] ?? '';
            if ($url === '') {
                fwrite(STDERR, "Usage: console redirects:test <path>\n");
                return 1;
            }
            [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
            $hit = $r->get(Store::class)->matcher()->test($path, $query);
            if ($hit === null) {
                echo "Žádné pravidlo.\n";
                return 1;
            }
            echo $hit['status'] . ' ' . ($hit['location'] ?? '(410 Gone)') . '   ← ' . $hit['rule']['from'] . "\n";
            return 0;
        }, 'Ukáže, kam pravidla pošlou danou URL');

        $r->command('redirects:add', function (array $args) use ($r): int {
            [$positional, $options] = self::parse($args);
            if (($positional[0] ?? '') === '') {
                fwrite(STDERR, "Usage: console redirects:add <from> <to> [--status=301] [--force] [--regex]\n");
                return 1;
            }
            try {
                $rule = $r->get(Store::class)->save([
                    'from'   => $positional[0],
                    'to'     => $positional[1] ?? '',
                    'status' => $options['status'] ?? 301,
                    'force'  => isset($options['force']),
                    'type'   => isset($options['regex']) ? 'regex' : '',
                    'note'   => 'console',
                ]);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                return 1;
            }
            echo 'Přidáno: ' . self::line($rule) . "\n";
            return 0;
        }, 'Přidá pravidlo: <from> <to> [--status=] [--force] [--regex]');

        $r->command('redirects:import', function (array $args) use ($r): int {
            $file = $args[0] ?? '';
            if ($file === '' || !is_readable($file)) {
                fwrite(STDERR, "Usage: console redirects:import <file.csv>   (sloupce from,to,status)\n");
                return 1;
            }
            $handle = fopen($file, 'r');
            $rows = (function () use ($handle) {
                while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    yield $row;
                }
            })();
            try {
                $result = $r->get(Store::class)->import($rows);
            } catch (\RuntimeException $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                return 1;
            } finally {
                fclose($handle);
            }
            foreach ($result['errors'] as $error) {
                fwrite(STDERR, "  {$error}\n");
            }
            echo "Importováno {$result['added']} pravidel" . ($result['errors'] ? ', ' . count($result['errors']) . ' řádků přeskočeno' : '') . ".\n";
            return $result['errors'] === [] ? 0 : 2;
        }, 'Importuje pravidla z CSV (from,to,status)');

        $r->command('redirects:compile', function () use ($r): int {
            $set = $r->get(Store::class)->compile();
            echo 'Zkompilováno ' . count($set['rules']) . ' pravidel' . ($set['hasForce'] ? ' (včetně vynucených)' : '') . ".\n";
            return 0;
        }, 'Přegeneruje writable/cache/redirects.php z _redirects.yaml');

        $r->command('redirects:404', function (array $args) use ($r): int {
            [, $options] = self::parse($args);
            $log = $r->get(Log::class);
            if (isset($options['clear'])) {
                echo 'Smazáno ' . $log->clear() . " záznamů.\n";
                return 0;
            }
            foreach ($log->entries((int) ($options['limit'] ?? 50)) as $e) {
                printf("%6d  %s  %s\n", $e['hits'], substr($e['last_seen'], 0, 16), $e['path']);
            }
            return 0;
        }, 'Vypíše log 404 [--limit=50] [--clear]');
    }

    private static function line(array $rule): string
    {
        $flags = array_filter([
            $rule['type'] !== 'exact' ? $rule['type'] : null,
            $rule['force'] ? 'force' : null,
            $rule['caseless'] ? 'caseless' : null,
            $rule['query'] === 'drop' ? 'query: drop' : null,
            $rule['auto'] !== null ? 'auto ' . $rule['auto'] : null,
        ]);
        return sprintf('%d  %s → %s%s', $rule['status'], $rule['from'], $rule['to'] ?? '—', $flags ? '  [' . implode(', ', $flags) . ']' : '');
    }

    /** @return array{list<string>, array<string, string|true>} */
    private static function parse(array $args): array
    {
        $positional = [];
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? true;
            } else {
                $positional[] = $arg;
            }
        }
        return [$positional, $options];
    }
}

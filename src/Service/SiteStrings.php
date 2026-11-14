<?php

declare(strict_types=1);

namespace Station0\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * The site's own UI strings (`t('key')` in Twig), per content language:
 * `site/lang/<lang>.yaml` (`paths.lang` overrides the directory). Nested
 * maps are addressed with dots (`nav.home`). Lookup: current language →
 * default language → $default → the key itself. `{name}` placeholders are
 * filled from $params.
 *
 * A one-language site reads `site/lang/default.yaml` when it exists.
 */
final class SiteStrings
{
    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    public function __construct(
        private readonly Languages $languages,
        private readonly string $dir,
    ) {}

    /** @param array<string, scalar|null> $params */
    public function get(string $key, array $params = [], ?string $default = null): string
    {
        $value = null;
        foreach ($this->chain() as $lang) {
            $value = $this->strings($lang)[$key] ?? null;
            if ($value !== null) {
                break;
            }
        }
        $value ??= $default ?? $key;

        if ($params !== []) {
            $replace = [];
            foreach ($params as $name => $param) {
                $replace['{' . $name . '}'] = (string) $param;
            }
            $value = strtr($value, $replace);
        }
        return $value;
    }

    /** @return list<string> */
    private function chain(): array
    {
        if (!$this->languages->enabled()) {
            return ['default'];
        }
        return array_values(array_unique([$this->languages->current(), $this->languages->default()]));
    }

    /** @return array<string, string> flattened `a.b` ⇒ string */
    private function strings(string $lang): array
    {
        if (isset($this->loaded[$lang])) {
            return $this->loaded[$lang];
        }
        $file = rtrim($this->dir, '/') . '/' . $lang . '.yaml';
        $data = [];
        if (is_file($file)) {
            try {
                $parsed = Yaml::parseFile($file);
                $data   = is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                $data = [];
            }
        }
        return $this->loaded[$lang] = self::flatten($data);
    }

    /** @return array<string, string> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $key . '.');
            } elseif (is_scalar($v)) {
                $out[$key] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Content languages of the site (`languages` config) and the language of the
 * current request.
 *
 *   'languages' => [
 *       'cs' => ['label' => 'Čeština'],          // first = default, no URL prefix
 *       'en' => ['label' => 'English', 'locale' => 'en_GB'],
 *   ],
 *   'languageFallback' => '404',                 // '404' (default) | 'content'
 *
 * No `languages` (or a single one) ⇒ not multilingual: every method answers
 * as for a one-language site and nothing else in the app changes.
 *
 * The default language is served without a prefix (`/about`), the others
 * under `/{code}` (`/en/about`). LanguageMiddleware strips the prefix before
 * routing and calls setCurrent(), so routes, AccessPolicy, actions and
 * redirect rules all see unprefixed paths. Page::$urlPath stays unprefixed
 * too; url() builds the public URL.
 */
final class Languages
{
    public const FALLBACK_404     = '404';
    public const FALLBACK_CONTENT = 'content';

    /** @var array<string, array{code: string, label: string, locale: string}> */
    private array $languages = [];

    private string $default = '';

    private string $current = '';

    private string $fallback = self::FALLBACK_404;

    /**
     * @param array<string, mixed>|list<string> $config  code ⇒ options, or a list of codes
     */
    public function __construct(array $config = [], string $fallback = self::FALLBACK_404)
    {
        foreach ($config as $key => $options) {
            $code = is_int($key) ? (string) $options : (string) $key;
            $options = is_array($options) ? $options : [];
            $code = strtolower(trim($code));
            if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code)) {
                continue;
            }
            $this->languages[$code] = [
                'code'   => $code,
                'label'  => (string) ($options['label'] ?? strtoupper($code)),
                'locale' => (string) ($options['locale'] ?? $code),
            ];
        }
        $this->default  = (string) (array_key_first($this->languages) ?? '');
        $this->current  = $this->default;
        $this->fallback = $fallback === self::FALLBACK_CONTENT ? self::FALLBACK_CONTENT : self::FALLBACK_404;
    }

    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['languages'] ?? []), (string) ($config['languageFallback'] ?? self::FALLBACK_404));
    }

    /** True when the site has two or more content languages. */
    public function enabled(): bool
    {
        return count($this->languages) > 1;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return $this->enabled() ? array_keys($this->languages) : [];
    }

    /** @return list<array{code: string, label: string, locale: string, default: bool}> */
    public function all(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        return array_values(array_map(
            fn (array $l): array => $l + ['default' => $l['code'] === $this->default],
            $this->languages,
        ));
    }

    public function has(string $code): bool
    {
        return $this->enabled() && isset($this->languages[$code]);
    }

    /** Default language code; '' when not multilingual. */
    public function default(): string
    {
        return $this->enabled() ? $this->default : '';
    }

    public function isDefault(string $code): bool
    {
        return !$this->enabled() || $code === '' || $code === $this->default;
    }

    /** Language of the current request; '' when not multilingual. */
    public function current(): string
    {
        return $this->enabled() ? $this->current : '';
    }

    public function setCurrent(string $code): void
    {
        if ($this->has($code)) {
            $this->current = $code;
        }
    }

    public function label(string $code): string
    {
        return $this->languages[$code]['label'] ?? $code;
    }

    public function locale(string $code): string
    {
        return $this->languages[$code]['locale'] ?? $code;
    }

    /** '404' — an untranslated page does not exist in that language; 'content' — it serves the default text. */
    public function fallback(): string
    {
        return $this->fallback;
    }

    /** URL prefix of a language: '' for the default, '/en' for the others. */
    public function prefix(?string $code = null): string
    {
        $code ??= $this->current();
        return $this->isDefault($code) || !$this->has($code) ? '' : '/' . $code;
    }

    /** Public URL of an unprefixed path in a language (current by default). */
    public function url(string $path, ?string $code = null): string
    {
        $path   = '/' . ltrim($path, '/');
        $prefix = $this->prefix($code);
        if ($prefix === '') {
            return $path;
        }
        return $path === '/' ? $prefix : $prefix . $path;
    }

    /**
     * Split a request path into [language, unprefixed path]. A path without a
     * known non-default prefix belongs to the default language.
     *
     * @return array{string, string}
     */
    public function split(string $path): array
    {
        if ($this->enabled() && preg_match('#^/([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(/.*)?$#', $path, $m)
            && $m[1] !== $this->default && isset($this->languages[$m[1]])) {
            return [$m[1], ($m[2] ?? '') === '' ? '/' : $m[2]];
        }
        return [$this->default(), $path];
    }

    /**
     * Name of the translation file next to a content file:
     * `/x/page.txt` + `en` ⇒ `/x/page.en.txt`.
     */
    public static function translationFile(string $contentFile, string $code): string
    {
        return substr($contentFile, 0, -4) . '.' . $code . '.txt';
    }

    /** True when $file is `<name>.<code>.txt` for one of the site's non-default languages. */
    public function isTranslationFile(string $file): bool
    {
        if (!$this->enabled() || !preg_match('/\.([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.txt$/', $file, $m)) {
            return false;
        }
        return $m[1] !== $this->default && isset($this->languages[$m[1]]);
    }
}

<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;
use Station0\Service\Languages;

/**
 * Multilingual sites: `/en/about` is routed as `/about` with English as the
 * current language (Languages::setCurrent()); the default language has no
 * prefix. Everything after this middleware — routes, AccessPolicy, actions,
 * redirect rules — sees the unprefixed path; the request attributes
 * `station0.lang` / `station0.langPrefix` keep what was stripped.
 *
 * Admin, `/media` and `/thumb` never take a prefix: `/en/admin/…` and
 * `/en/media/…` are left alone (and end as 404).
 */
final class LanguageMiddleware implements MiddlewareInterface
{
    public const ATTR_LANG   = 'station0.lang';
    public const ATTR_PREFIX = 'station0.langPrefix';
    /** Closure(string): string — see localize(). */
    public const ATTR_LOCALIZE = 'station0.localize';

    /**
     * A local path in the request's language: `/contact` → `/en/contact` on
     * an English request. Left alone: the default language, external URLs,
     * paths that already carry a language prefix, and admin / `/media` /
     * `/thumb`. Without LanguageMiddleware (not multilingual) $path as is.
     * For redirects built after routing (access, actions, redirect rules).
     */
    public static function localize(Request $request, string $path): string
    {
        $localize = $request->getAttribute(self::ATTR_LOCALIZE);
        return $localize instanceof \Closure ? $localize($path) : $path;
    }

    public function __construct(
        private readonly Languages $languages,
        private readonly string $adminPath = '/admin',
        private readonly ?Twig $twig = null,
    ) {}

    /**
     * The `languages` Twig global: each language with its home URL and
     * whether it is the current one (switcher for pages without a translation
     * list, e.g. 404 pages).
     *
     * @return list<array<string, mixed>>
     */
    public static function languagesGlobal(Languages $languages): array
    {
        return array_map(fn (array $l): array => $l + [
            'url'     => $languages->url('/', $l['code']),
            'current' => $l['code'] === $languages->current(),
        ], $languages->all());
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->languages->enabled()) {
            return $handler->handle($request);
        }

        $uri  = $request->getUri();
        $path = $uri->getPath();
        [$lang, $inner] = $this->languages->split($path);

        if ($inner !== $path && !$this->unprefixable($inner)) {
            $request = $request->withUri($uri->withPath($inner));
        } else {
            $lang = $this->languages->default();
        }

        $this->languages->setCurrent($lang);
        if ($this->twig !== null) {
            $env = $this->twig->getEnvironment();
            $env->addGlobal('lang', $lang);
            $env->addGlobal('languages', self::languagesGlobal($this->languages));
        }

        return $handler->handle($request
            ->withAttribute(self::ATTR_LANG, $lang)
            ->withAttribute(self::ATTR_PREFIX, $this->languages->prefix($lang))
            ->withAttribute(self::ATTR_LOCALIZE, fn (string $path): string => $this->localizePath($path, $lang)));
    }

    private function localizePath(string $path, string $lang): string
    {
        $prefix = $this->languages->prefix($lang);
        if ($prefix === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }
        $cut  = strcspn($path, '?#');
        $only = substr($path, 0, $cut);
        $rest = substr($path, $cut);
        if ($this->languages->split($only)[1] !== $only || $this->unprefixable($only)) {
            return $path; // already in a language, or never prefixed
        }
        return ($only === '/' ? $prefix : $prefix . $only) . $rest;
    }

    private function unprefixable(string $path): bool
    {
        foreach ([rtrim($this->adminPath, '/'), '/media', '/thumb'] as $root) {
            if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
                return true;
            }
        }
        return false;
    }
}

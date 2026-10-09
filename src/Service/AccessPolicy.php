<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Who may see what on the public site — the `access` section of site/config.php:
 *
 *   'access' => [
 *       'mode'      => 'members',        // 'public' (default) | 'members'
 *       'public'    => ['/', '/kontakt', '/info/*'],  // always public; `*` = prefix
 *       'redirect'  => '/',              // anonymous visitors of a gated page go here
 *       'media'     => true,             // members mode: gate /media + /thumb too (default true)
 *       'loginPath' => '/login',         // enables the member sign-in routes
 *   ],
 *
 * A page can override the mode with `Access: public` or `Access: members` in
 * its front matter; the setting is inherited by its sub-pages (the homepage's
 * own setting applies to `/` only). The redirect target and the sign-in
 * routes are always public.
 *
 * Media are gated as a whole (members mode + `media`), not per page.
 */
final class AccessPolicy
{
    public const MEMBERS = 'members';
    public const PUBLIC  = 'public';

    private readonly string $mode;
    /** @var list<string> */
    private readonly array $publicPaths;

    /**
     * @param \Closure(string): ?Page|null $findPage page by URL path (published or not)
     */
    public function __construct(private readonly array $access = [], private readonly ?\Closure $findPage = null)
    {
        $this->mode = ($access['mode'] ?? self::PUBLIC) === self::MEMBERS ? self::MEMBERS : self::PUBLIC;

        $paths = array_map(fn ($p) => self::normalize((string) $p), (array) ($access['public'] ?? []));
        $paths[] = $this->redirect();
        if ($this->loginPath() !== null) {
            $paths[] = $this->loginPath();
            $paths[] = $this->loginPath() . '/*';
        }
        $this->publicPaths = array_values(array_unique($paths));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** Where an anonymous visitor of a gated page is sent. */
    public function redirect(): string
    {
        return self::normalize((string) ($this->access['redirect'] ?? '/'));
    }

    /** Base path of the member sign-in routes, or null when they are off. */
    public function loginPath(): ?string
    {
        $path = trim((string) ($this->access['loginPath'] ?? ''));
        return $path === '' ? null : self::normalize($path);
    }

    public function pageRequiresMember(string $urlPath): bool
    {
        $urlPath = self::normalize($urlPath);
        if ($this->isPublicPath($urlPath)) {
            return false;
        }
        $setting = $this->pageSetting($urlPath);
        return $setting !== null ? $setting === self::MEMBERS : $this->mode === self::MEMBERS;
    }

    /** $path: the asset path after /media/ (or after the thumb signature). */
    public function mediaRequiresMember(string $path): bool
    {
        if ($this->mode !== self::MEMBERS || ($this->access['media'] ?? true) === false) {
            return false;
        }
        return !$this->isPublicPath('/media/' . ltrim($path, '/'));
    }

    public function isPublicPath(string $urlPath): bool
    {
        $urlPath = self::normalize($urlPath);
        foreach ($this->publicPaths as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim(substr($pattern, 0, -1), '/');
                if ($urlPath === ($prefix === '' ? '/' : $prefix) || str_starts_with($urlPath, $prefix . '/')) {
                    return true;
                }
            } elseif ($urlPath === $pattern) {
                return true;
            }
        }
        return false;
    }

    /** The nearest `Access:` front-matter setting of the page or its ancestors. */
    private function pageSetting(string $urlPath): ?string
    {
        if ($this->findPage === null) {
            return null;
        }
        $path = $urlPath;
        while (true) {
            $page  = ($this->findPage)($path);
            $value = $page !== null ? strtolower(trim((string) ($page->extra['access'] ?? ''))) : '';
            if ($value === self::MEMBERS || $value === self::PUBLIC) {
                return $value;
            }
            if ($path === '/') {
                return null;
            }
            $path = rtrim(dirname($path), '/') ?: '/';
            if ($path === '/') {
                return null; // the homepage's own setting is not inherited
            }
        }
    }

    private static function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }
}

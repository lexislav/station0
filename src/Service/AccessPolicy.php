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
 * Media follow the page they belong to (`/media/blog/post/photo.jpg` → `/blog/post`),
 * so a members-only section keeps its photos and files private on a public
 * site too; `media: false` leaves all media public. Collection assets
 * (`/media/_collections/…`) follow the mode.
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
        $path = ltrim($path, '/');
        if (($this->access['media'] ?? true) === false || $this->isPublicPath('/media/' . $path)) {
            return false;
        }
        $parts = explode('/', $path);
        array_pop($parts); // the file name
        if ($parts === [] || $parts[0] === MediaService::COLLECTIONS_TOKEN) {
            return $this->mode === self::MEMBERS;
        }
        // Page-local asset: /media/{page path}/{file}, the homepage's under `~`.
        $pagePath = $parts === [MediaService::ROOT_TOKEN] ? '/' : '/' . implode('/', $parts);
        return $this->pageRequiresMember($pagePath);
    }

    /**
     * Pages an anonymous visitor may see in listings (menus, child_pages(),
     * page()) — gated pages are left out so their titles and teasers don't leak.
     *
     * @param list<Page> $pages
     * @return list<Page>
     */
    public function visiblePages(array $pages, bool $authenticated): array
    {
        if ($authenticated) {
            return $pages;
        }
        return array_values(array_filter($pages, fn (Page $p) => !$this->pageRequiresMember($p->urlPath)));
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

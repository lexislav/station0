<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Station0\Service\ContentRepository;
use Station0\Service\Page;
use Station0\Service\PageFields;
use Station0\Service\PageRenderer;
use Station0\Support\Visibility;

final class PageController
{
    /** @var \Closure(): bool */
    private readonly \Closure $canPreview;

    /**
     * @param ?callable(): bool $canPreview  true for a signed-in admin / editor;
     *        only called for a page that is not live
     * @param array<string, string> $lang    admin translations (preview bar)
     */
    public function __construct(
        private readonly ContentRepository $content,
        private readonly PageRenderer $renderer,
        private readonly Twig $twig,
        private readonly PageFields $fields,
        ?callable $canPreview = null,
        private readonly string $adminPath = '/admin',
        private readonly array $lang = [],
    ) {
        $this->canPreview = $canPreview !== null ? \Closure::fromCallable($canPreview) : static fn (): bool => false;
    }

    public function home(Request $request, Response $response): Response
    {
        return $this->renderPage($request, $response, '/');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        // $args['slug'] contains the full path, e.g. "about/team" (no leading /)
        $urlPath = '/' . ltrim($args['slug'], '/');
        return $this->renderPage($request, $response, $urlPath);
    }

    private function renderPage(Request $request, Response $response, string $urlPath): Response
    {
        $page = $this->content->find($urlPath);

        if ($page === null) {
            return $this->notFound($response, $urlPath, 404);
        }

        // A non-live page: signed-in editors get a preview, everyone else
        // 404 — or 410 when it (or the hiding ancestor) is archived / expired.
        $preview = !$page->isLive();
        if ($preview && !($this->canPreview)()) {
            return $this->notFound($response, $urlPath, $page->httpStatus());
        }

        $html     = $this->renderer->render($page, $this->content->mtime($page->urlPath));
        $template = $page->template ?: 'page';

        $out = $this->twig->fetch($template . '.twig', [
            'page'    => $page,
            'content' => $html,
            'fields'  => $this->fields->resolved($page),
            'preview' => $preview,
        ]);

        if ($preview) {
            $out = $this->injectPreviewBar($out, $page);
            $response = $response
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $response->getBody()->write($out);
        return $response;
    }

    /** 404 / 410 page; a 410 uses the site's `410.twig` when it has one. */
    private function notFound(Response $response, string $urlPath, int $status): Response
    {
        $template = $status === 410 && $this->twig->getLoader()->exists('410.twig') ? '410.twig' : '404.twig';
        return $this->twig->render($response->withStatus($status), $template, [
            'urlPath' => $urlPath,
            'status'  => $status,
        ]);
    }

    /**
     * A fixed "not public" bar right after <body> — no site template changes
     * needed. Templates can also check the `preview` variable themselves.
     */
    private function injectPreviewBar(string $html, Page $page): string
    {
        $t     = fn (string $key): string => $this->lang[$key] ?? $key;
        $esc   = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5);
        $state = $page->state();

        $label = $t('pages_status_' . $state);
        if ($state === Visibility::SCHEDULED && $page->publishTime() !== null) {
            $label .= ' — ' . sprintf($t('pages_status_scheduled_title'), date('j. n. Y H:i', $page->publishTime()));
        } elseif ($state === Visibility::EXPIRED && $page->expireTime() !== null) {
            $label .= ' — ' . sprintf($t('pages_status_expires_title'), date('j. n. Y H:i', $page->expireTime()));
        } elseif ($state === Visibility::HIDDEN && ($blocker = $page->blockingAncestor()) !== null) {
            $label .= ' — ' . sprintf($t('pages_status_hidden_title'), $blocker->title . ' (' . $blocker->urlPath . ')');
        }

        $editKey = $page->urlPath === '/' ? '~' : ltrim($page->urlPath, '/');
        $bar = '<div id="station0-preview-bar" role="status" style="position:sticky;top:0;z-index:2147483647;'
            . 'display:flex;gap:1rem;align-items:center;justify-content:space-between;padding:.5rem 1rem;'
            . 'background:#7c2d12;color:#fff;font:14px/1.4 system-ui,sans-serif">'
            . '<span>' . $esc(sprintf($t('preview_bar_text'), $label)) . '</span>'
            . '<a href="' . $esc($this->adminPath . '/pages/' . $editKey . '/edit') . '" style="color:#fff;font-weight:600">'
            . $esc($t('preview_bar_edit')) . '</a></div>';

        $count = 0;
        $html  = (string) preg_replace('/<body\b[^>]*>/i', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $bar), $html, 1, $count);
        return $count > 0 ? $html : $bar . $html;
    }
}

<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Station0\Service\ContentRepository;
use Station0\Service\PageFields;
use Station0\Service\PageRenderer;

final class PageController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly PageRenderer $renderer,
        private readonly Twig $twig,
        private readonly PageFields $fields,
    ) {}

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
        if (!$page->isLive()) {
            // Archived / expired (or under such a page) = gone for good → 410.
            return $this->notFound($response, $urlPath, $page->httpStatus());
        }

        $html     = $this->renderer->render($page, $this->content->mtime($page->urlPath));
        $template = $page->template ?: 'page';

        return $this->twig->render($response, $template . '.twig', [
            'page'    => $page,
            'content' => $html,
            'fields'  => $this->fields->resolved($page),
        ]);
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
}

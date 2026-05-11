<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Station0\Service\ContentRepository;
use Station0\Service\PageRenderer;

final class PageController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly PageRenderer $renderer,
        private readonly Twig $twig,
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

        if ($page === null || !$page->isLive()) {
            $response = $response->withStatus(404);
            return $this->twig->render($response, '404.twig', ['urlPath' => $urlPath]);
        }

        $html     = $this->renderer->render($page, $this->content->mtime($page->urlPath));
        $template = $page->template ?: 'page';

        return $this->twig->render($response, $template . '.twig', [
            'page'    => $page,
            'content' => $html,
        ]);
    }
}

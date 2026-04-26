<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Station0\Service\ContentRepository;

final class DashboardController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Twig $twig,
        private readonly ContentRepository $content,
        private readonly array $rolesMap,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $pages = $this->content->all();
        return $this->twig->render($response, '@admin/dashboard.twig', [
            'user' => [
                'email' => $this->auth->getEmail(),
                'isAdmin' => $this->auth->hasRole($this->rolesMap['admin']),
            ],
            'pageCount' => count($pages),
        ]);
    }
}

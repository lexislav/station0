<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Station0\Service\UserRepository;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly string $adminPath,
        private readonly Twig $twig,
        private readonly array $rolesMap,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->isLoggedIn()) {
            $response = (new ResponseFactory())->createResponse(302);
            $target = $this->users->hasAny() ? '/login' : '/setup';
            return $response->withHeader('Location', $this->adminPath . $target);
        }
        $this->twig->getEnvironment()->addGlobal('user', [
            'email'   => $this->auth->getEmail(),
            'isAdmin' => $this->auth->hasRole($this->rolesMap['admin']),
        ]);
        return $handler->handle($request);
    }
}

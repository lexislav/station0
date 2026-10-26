<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/** 403 unless the signed-in user has the role; `admin` always passes. */
final class RoleMiddleware implements MiddlewareInterface
{
    private readonly int $roleValue;
    private readonly ?int $adminValue;

    public function __construct(private readonly Auth $auth, array $rolesMap, string $roleName)
    {
        if (!isset($rolesMap[$roleName])) {
            throw new \InvalidArgumentException("Unknown role: {$roleName}");
        }
        $this->roleValue  = $rolesMap[$roleName];
        $this->adminValue = $rolesMap['admin'] ?? null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $allowed = $this->auth->isLoggedIn() && ($this->auth->hasRole($this->roleValue)
            || ($this->adminValue !== null && $this->auth->hasRole($this->adminValue)));
        if (!$allowed) {
            $response = (new ResponseFactory())->createResponse(403);
            $response->getBody()->write('Forbidden');
            return $response;
        }
        return $handler->handle($request);
    }
}

<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Anti-framing and referrer headers on every admin response.
 *
 * Added outermost (after the error middleware), so error pages, CSRF
 * failures and unrouted /admin/* 404s carry the headers too. Headers a
 * controller already set are left alone.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const HEADERS = [
        'X-Frame-Options'         => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'Referrer-Policy'         => 'same-origin',
        'X-Content-Type-Options'  => 'nosniff',
    ];

    public function __construct(private readonly string $adminPath) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!$this->isAdmin($request->getUri()->getPath())) {
            return $response;
        }
        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }

    private function isAdmin(string $path): bool
    {
        $admin = rtrim($this->adminPath, '/');
        return $admin !== '' && ($path === $admin || str_starts_with($path, $admin . '/'));
    }
}

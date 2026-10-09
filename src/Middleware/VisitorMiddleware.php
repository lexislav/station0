<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use Station0\Service\Visitor;
use Station0\Support\VisitorGlobal;

/**
 * Loads the public-site visitor (pass cookie) for every request, exposes it
 * to templates as the `visitor` global and writes any pass cookie set or
 * cleared during the request onto the response.
 */
final class VisitorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Visitor $visitor,
        private readonly Twig $twig,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->visitor->load($request->getCookieParams());
        $this->twig->getEnvironment()->addGlobal('visitor', new VisitorGlobal($this->visitor));

        $response = $handler->handle($request);

        foreach ($this->visitor->pendingCookies() as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }
        return $response;
    }
}

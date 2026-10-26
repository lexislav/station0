<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * `force: true` rules — they apply even when a page exists at the path.
 * Added only when the compiled rule set has force rules (see Plugin).
 */
final class ForceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Redirector $redirector) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->redirector->force($request, (new ResponseFactory())->createResponse())
            ?? $handler->handle($request);
    }
}

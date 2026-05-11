<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;

final class CsrfTwigGlobalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Guard $csrf,
        private readonly Twig $twig,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nameKey = $this->csrf->getTokenNameKey();
        $valueKey = $this->csrf->getTokenValueKey();

        $this->twig->getEnvironment()->addGlobal('csrf', [
            'nameKey'  => $nameKey,
            'valueKey' => $valueKey,
            'name'     => $request->getAttribute($nameKey),
            'value'    => $request->getAttribute($valueKey),
        ]);

        return $handler->handle($request);
    }
}

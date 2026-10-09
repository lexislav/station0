<?php

declare(strict_types=1);

namespace Station0\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;
use Station0\Service\AccessPolicy;
use Station0\Service\Visitor;

/**
 * Members-only access (config `access`, see AccessPolicy) for the public page
 * routes and — when gated — the /media and /thumb routes.
 *
 * Anonymous visitors of a gated page are redirected to `access.redirect`;
 * gated media answer 403. Gated responses are never cached publicly.
 */
final class AccessMiddleware implements MiddlewareInterface
{
    public const PAGES = 'pages';
    public const MEDIA = 'media';

    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly Visitor $visitor,
        private readonly string $kind = self::PAGES,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $gated = $this->kind === self::MEDIA
            ? $this->policy->mediaRequiresMember($this->mediaPath($request))
            : $this->policy->pageRequiresMember(rawurldecode($request->getUri()->getPath()));

        if (!$gated) {
            return $handler->handle($request);
        }

        if (!$this->visitor->isAuthenticated()) {
            $response = (new ResponseFactory())->createResponse($this->kind === self::MEDIA ? 403 : 302);
            if ($this->kind === self::PAGES) {
                $response = $response->withHeader('Location', $this->policy->redirect());
            }
            return $response->withHeader('Cache-Control', 'no-store');
        }

        $response = $handler->handle($request);
        if ($this->kind === self::MEDIA) {
            // Browser may keep it, shared caches/CDNs must not.
            return $response->getStatusCode() === 200
                ? $response->withHeader('Cache-Control', 'private, max-age=86400')
                : $response;
        }
        return $response->withHeader('Cache-Control', 'private, no-store');
    }

    private function mediaPath(ServerRequestInterface $request): string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        return (string) ($route?->getArgument('path') ?? '');
    }
}

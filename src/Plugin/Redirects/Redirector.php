<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Answers requests from the rules: the 404/410 fallback (notFound resolver)
 * and `force` rules (ForceMiddleware). Builds the redirect / 410 response.
 */
final class Redirector
{
    /**
     * @param \Closure(): Log  $log   built lazily — only a 404 or a counted rule opens the DB
     * @param \Closure(): Twig $twig  for 410 pages
     */
    public function __construct(
        private readonly Store $store,
        private readonly \Closure $log,
        private readonly \Closure $twig,
        private readonly int $maxAge = 3600,
        private readonly bool $log404 = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** notFound() resolver: a rule for the missing path, else log the miss. */
    public function notFound(Request $request, Response $response, string $path, int $status): ?Response
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $query   = $request->getUri()->getQuery();
        $matcher = $this->store->matcher();
        $hit     = $matcher->match($path, $query);

        // Assets travel with their page: /media/old/x.jpg follows the page's subtree rule.
        if ($hit === null && str_starts_with($path, '/media/')) {
            $inner = $matcher->match(substr($path, 6), $query);
            if ($inner !== null && $inner['rule']['auto'] !== null && str_starts_with((string) $inner['location'], '/')) {
                $hit = ['location' => '/media' . $inner['location']] + $inner;
            }
        }
        if ($hit !== null) {
            return $this->respond($response, $hit, $path);
        }

        if ($status === 404 && $this->log404) {
            try {
                ($this->log)()->record404($path, $request->getHeaderLine('Referer'));
            } catch (\Throwable $e) {
                $this->logger?->error('Redirects: 404 log failed: ' . $e->getMessage());
            }
        }
        return null;
    }

    /** A `force` rule for this request (GET / HEAD only), else null. */
    public function force(Request $request, Response $response): ?Response
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = rawurldecode($request->getUri()->getPath());
        $hit  = $this->store->forceMatcher()->match($path, $request->getUri()->getQuery(), true);
        return $hit !== null ? $this->respond($response, $hit, $path) : null;
    }

    /** @param array{rule: array, status: int, location: ?string} $hit */
    public function respond(Response $response, array $hit, string $path): Response
    {
        if ($hit['rule']['count']) {
            try {
                ($this->log)()->hit($hit['rule']['id']);
            } catch (\Throwable $e) {
                $this->logger?->error('Redirects: hit counter failed: ' . $e->getMessage());
            }
        }
        if ($hit['status'] === 410 || $hit['location'] === null) {
            $twig     = ($this->twig)();
            $template = $twig->getLoader()->exists('410.twig') ? '410.twig' : '404.twig';
            return $twig->render($response->withStatus(410), $template, ['urlPath' => $path, 'status' => 410]);
        }
        $permanent = in_array($hit['status'], [301, 308], true);
        return $response
            ->withStatus($hit['status'])
            ->withHeader('Location', $hit['location'])
            ->withHeader('Cache-Control', $permanent ? 'public, max-age=' . max(0, $this->maxAge) : 'no-store');
    }
}

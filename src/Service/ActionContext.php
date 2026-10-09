<?php

declare(strict_types=1);

namespace Station0\Service;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * What a site action's handler receives (see ActionRegistry):
 *
 *   'handler' => function (ActionContext $ctx) {
 *       if (!$ctx->throttle()->hit('contact:' . $ctx->ip(), 5, 3600)['allowed']) {
 *           $ctx->state()->flash('error', 'throttled');
 *           return $ctx->back();
 *       }
 *       $ctx->mail()->send('me@example.com', 'Contact', e($ctx->input('message')));
 *       $ctx->state()->flash('sent', true);
 *       return $ctx->redirect('/contact');
 *   }
 *
 * The handler returns a response (redirect(), back(), render(), json()…);
 * returning null redirects back.
 */
final class ActionContext
{
    /** @param array<string, mixed> $session the session array (by reference) */
    public function __construct(
        public readonly string $name,
        private readonly Request $request,
        private readonly Twig $twig,
        private readonly array $config,
        private readonly Visitor $visitor,
        private readonly Members $members,
        private readonly RateLimiter $limiter,
        private readonly ContentRepository $pages,
        private readonly CollectionRepository $collections,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
        private array &$session,
    ) {}

    public function request(): Request
    {
        return $this->request;
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    /** A submitted value (POST body first, then query string); strings are trimmed. */
    public function input(string $name, mixed $default = null): mixed
    {
        $body  = $this->request->getParsedBody();
        $value = is_array($body) && array_key_exists($name, $body)
            ? $body[$name]
            : ($this->request->getQueryParams()[$name] ?? $default);
        return is_string($value) ? trim($value) : $value;
    }

    public function ip(): string
    {
        return (string) ($this->request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /** Session-backed state of this action (or of another namespace). */
    public function state(?string $namespace = null): ActionState
    {
        return new ActionState($this->session, $namespace ?? $this->name);
    }

    public function visitor(): Visitor
    {
        return $this->visitor;
    }

    public function members(): Members
    {
        return $this->members;
    }

    public function throttle(): RateLimiter
    {
        return $this->limiter;
    }

    public function pages(): ContentRepository
    {
        return $this->pages;
    }

    public function collections(): CollectionRepository
    {
        return $this->collections;
    }

    public function mail(): MailerService
    {
        return $this->mailer;
    }

    public function log(): LoggerInterface
    {
        return $this->logger;
    }

    /** The site config, or one value by dotted key: config('access.mode'). */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        $value = $this->config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Redirect to a path on this site (anything else falls back to "/"). */
    public function redirect(string $path, int $status = 303): Response
    {
        return (new ResponseFactory())->createResponse($status)
            ->withHeader('Location', self::localPath($path) ?? '/')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Redirect to the page the form was posted from (same site only). */
    public function back(string $fallback = '/'): Response
    {
        $referer = $this->request->getHeaderLine('Referer');
        $path    = null;
        if ($referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host === null || $host === $this->request->getUri()->getHost()) {
                $path  = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
                $query = parse_url($referer, PHP_URL_QUERY);
                $path .= $query ? '?' . $query : '';
            }
        }
        return $this->redirect($path ?? $fallback);
    }

    /** Render a site template (e.g. a confirmation page). */
    public function render(string $template, array $vars = [], int $status = 200): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        return $this->twig->render($response, $template, $vars)->withHeader('Cache-Control', 'no-store');
    }

    public function json(mixed $data, int $status = 200): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    /** $path when it is a path on this site ("/x", not "//host" or "http:…"), else null. */
    public static function localPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F]/', $path)) {
            return null;
        }
        return $path;
    }
}

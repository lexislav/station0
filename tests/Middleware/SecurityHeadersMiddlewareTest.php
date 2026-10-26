<?php

declare(strict_types=1);

namespace Station0\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Station0\Middleware\SecurityHeadersMiddleware;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function respond(string $path, int $status = 200, array $preset = []): ResponseInterface
    {
        $handler = new class ($status, $preset) implements RequestHandlerInterface {
            public function __construct(private int $status, private array $preset) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse($this->status);
                foreach ($this->preset as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
                return $response;
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return (new SecurityHeadersMiddleware('/admin'))->process($request, $handler);
    }

    public static function adminPaths(): array
    {
        return [
            'dashboard'   => ['/admin', 200],
            'login'       => ['/admin/login', 200],
            'setup'       => ['/admin/setup', 302],
            'deep page'   => ['/admin/pages/blog/post/edit', 200],
            'forbidden'   => ['/admin/users', 403],
            'unrouted'    => ['/admin/nope', 404],
            'asset'       => ['/admin/assets/admin.css', 200],
        ];
    }

    /** @dataProvider adminPaths */
    public function testAdminResponsesGetHeaders(string $path, int $status): void
    {
        $response = $this->respond($path, $status);

        self::assertSame($status, $response->getStatusCode());
        foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name);
        }
    }

    public function testPublicResponsesAreUntouched(): void
    {
        foreach (['/', '/administrator', '/blog/admin', '/media/admin/x.jpg'] as $path) {
            $response = $this->respond($path);
            foreach (array_keys(SecurityHeadersMiddleware::HEADERS) as $name) {
                self::assertFalse($response->hasHeader($name), "{$path}: {$name}");
            }
        }
    }

    public function testExistingHeaderIsKept(): void
    {
        $response = $this->respond('/admin/pages', 200, ['Referrer-Policy' => 'no-referrer']);

        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }
}

<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Station0\Service\MediaService;
use Station0\Service\ThumbService;

/**
 * Serves page-local assets stored next to content files, and their thumbnails.
 * Routes: GET /media/{path:.+}, GET /thumb/{spec}/{sig}/{path:.+}
 */
final class AssetController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly ?ThumbService $thumbs = null,
    ) {}

    public function thumb(Request $request, Response $response, array $args): Response
    {
        $path  = (string) ($args['path'] ?? '');
        $thumb = $this->thumbs?->resolve((string) ($args['spec'] ?? ''), (string) ($args['sig'] ?? ''), $path);
        if ($thumb === null) {
            return $response->withStatus(404);
        }
        if ($thumb['fallback']) {
            // Could not be resized (e.g. too large to decode) — send the original,
            // without caching the redirect so a later attempt can succeed.
            $url = '/media/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $url)
                ->withHeader('Cache-Control', 'no-store');
        }
        return $this->send($response, $thumb['path'], $thumb['mime']);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $path = (string) ($args['path'] ?? '');
        $asset = $this->media->resolveAsset($path);
        if ($asset === null) {
            return $response->withStatus(404);
        }

        $response = $this->send($response, $asset['path'], $asset['mime']);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // SVGs can carry embedded <script>. They still render fine via <img>/CSS,
        // but a sandbox CSP neutralizes script execution if one is opened directly,
        // and we force-download rather than render as a top-level document.
        $isSvg = $asset['mime'] === 'image/svg+xml';
        if ($isSvg) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            );
        }

        // Documents and SVGs are downloaded; raster images are rendered inline.
        if ($asset['download'] || $isSvg) {
            $filename = rawurlencode(basename($asset['path']));
            $response = $response->withHeader(
                'Content-Disposition',
                'attachment; filename="' . basename($asset['path']) . '"; filename*=UTF-8\'\'' . $filename,
            );
        }

        return $response;
    }

    private function send(Response $response, string $path, string $mime): Response
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }
        $body = $response->getBody();
        while (!feof($stream)) {
            $body->write((string) fread($stream, 8192));
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}

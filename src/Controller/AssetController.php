<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Station0\Service\MediaService;

/**
 * Serves page-local assets stored next to content files.
 * Route: GET /media/{path:.+}
 */
final class AssetController
{
    public function __construct(private readonly MediaService $media) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        $path = (string) ($args['path'] ?? '');
        $asset = $this->media->resolveAsset($path);
        if ($asset === null) {
            return $response->withStatus(404);
        }

        $stream = fopen($asset['path'], 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }
        $body = $response->getBody();
        while (!feof($stream)) {
            $body->write((string) fread($stream, 8192));
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', $asset['mime'])
            ->withHeader('Content-Length', (string) filesize($asset['path']))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}

<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class UploadController
{
    private const ALLOWED_MIME = [
        'image/jpeg'     => 'jpg',
        'image/png'      => 'png',
        'image/gif'      => 'gif',
        'image/webp'     => 'webp',
        'image/svg+xml'  => 'svg',
        'text/html'      => 'svg', // some systems misdetect SVG as text/html
    ];
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly string $uploadsDir,
        private readonly string $uploadsUrl,
    ) {}

    public function store(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        $file = $files['image'] ?? $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response->withStatus(400), ['error' => 'No file uploaded']);
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_BYTES) {
            return $this->json($response->withStatus(413), ['error' => 'File too large']);
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath)) {
            return $this->json($response->withStatus(400), ['error' => 'Cannot read uploaded file']);
        }

        $mime = $this->detectMime($tmpPath, (string) $file->getClientFilename());
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return $this->json($response->withStatus(415), ['error' => 'Unsupported type: ' . $mime]);
        }

        $ext = self::ALLOWED_MIME[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $target = rtrim($this->uploadsDir, '/') . '/' . $name;
        $file->moveTo($target);

        return $this->json($response, [
            'url' => rtrim($this->uploadsUrl, '/') . '/' . $name,
        ]);
    }

    private function detectMime(string $path, string $clientName): string
    {
        $mime = mime_content_type($path) ?: '';

        // mime_content_type misdetects SVG as text/plain or text/html — check by content
        if (!isset(self::ALLOWED_MIME[$mime]) || $mime === 'text/html' || $mime === 'text/plain') {
            $head = file_get_contents($path, false, null, 0, 512) ?: '';
            if (stripos($head, '<svg') !== false && stripos($head, 'xmlns') !== false) {
                return 'image/svg+xml';
            }
        }

        // Fallback: trust client extension only for SVG
        if (!isset(self::ALLOWED_MIME[$mime])) {
            $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                return 'image/svg+xml';
            }
        }

        return $mime;
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

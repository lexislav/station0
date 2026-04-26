<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class UploadController
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
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

        $tmpStream = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmpStream) ? (mime_content_type($tmpStream) ?: '') : '';
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return $this->json($response->withStatus(415), ['error' => 'Unsupported type']);
        }

        $ext = self::ALLOWED_MIME[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $target = rtrim($this->uploadsDir, '/') . '/' . $name;
        $file->moveTo($target);

        return $this->json($response, [
            'url' => rtrim($this->uploadsUrl, '/') . '/' . $name,
        ]);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

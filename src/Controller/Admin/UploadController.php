<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Station0\Service\MediaService;

final class UploadController
{
    public function __construct(private readonly MediaService $media) {}

    public function store(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response->withStatus(400), [
                'error' => 'No file field in request (got: ' . implode(', ', array_keys($files)) . ')',
            ]);
        }

        $body     = (array) $request->getParsedBody();
        $pagePath = trim((string) ($body['pagePath'] ?? ''));
        if ($pagePath === '') {
            return $this->json($response->withStatus(422), [
                'error' => 'Save the page first — assets are stored alongside the page.',
            ]);
        }

        try {
            $result = $this->media->storeForPage($pagePath, $file);
        } catch (\RuntimeException $e) {
            $msg    = $e->getMessage();
            $status = match (true) {
                str_contains($msg, 'too large') || str_contains($msg, 'exceeds') => 413,
                str_starts_with($msg, 'Unsupported')                              => 415,
                str_contains($msg, 'No file')                                     => 400,
                default                                                            => 422,
            };
            return $this->json($response->withStatus($status), ['error' => $msg]);
        }

        return $this->json($response, $result);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

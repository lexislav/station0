<?php

declare(strict_types=1);

namespace Station0\Service;

use Psr\Http\Message\UploadedFileInterface;
use Station0\Support\Slug;

/**
 * Stores page-local assets next to the page's content file.
 *
 *   site/content/pages/about/page.txt
 *   site/content/pages/about/team-photo.jpg   ← asset
 *
 * Public URL: /media/{page-url-path}/{filename}.
 * Root-page assets are addressed by `/media/~/{filename}`.
 */
final class MediaService
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Allowed extensions → canonical mime (used for serving). */
    public const ALLOWED_EXT = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ];

    /** Mimes accepted on upload → extension. */
    private const ALLOWED_MIME = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public const ROOT_TOKEN = '~';

    public function __construct(
        private readonly ContentRepository $content,
        private readonly string $pagesDir,
    ) {}

    // ─── Upload ───

    /**
     * Store an uploaded file inside the directory of the given page.
     *
     * @return array{filename: string, url: string}
     * @throws \RuntimeException
     */
    public function storeForPage(string $pageUrlPath, UploadedFileInterface $file): array
    {
        $err = $file->getError();
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadErrorMessage($err));
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('File too large');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath)) {
            throw new \RuntimeException('Cannot read uploaded file');
        }

        $clientName = (string) $file->getClientFilename();
        $mime       = $this->detectMime($tmpPath, $clientName);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new \RuntimeException('Unsupported type: ' . $mime);
        }

        $targetDir = $this->resolvePageDir($pageUrlPath);
        $name      = $this->resolveFilename($targetDir, $clientName, self::ALLOWED_MIME[$mime]);

        $file->moveTo($targetDir . '/' . $name);

        return [
            'filename' => $name,
            'url'      => $this->urlFor($pageUrlPath, $name),
        ];
    }

    /**
     * Build the public URL for a page-local asset.
     */
    public function urlFor(string $pageUrlPath, string $filename): string
    {
        return $this->buildUrl($pageUrlPath, $filename);
    }

    /**
     * Resolve a stored asset reference to a public URL.
     *
     *  - empty / contains "://" / starts with "/"  → returned unchanged
     *    (external URL or already-absolute /media path or legacy /uploads)
     *  - bare "filename.ext"                       → /media/{pageUrlPath}/filename.ext
     *
     * Use this when rendering an image-field value or rewriting markdown.
     */
    public function resolveRef(string $value, string $pageUrlPath): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '://') || str_starts_with($value, '/') || str_starts_with($value, '#')) {
            return $value;
        }
        return $this->urlFor($pageUrlPath, $value);
    }

    // ─── Serving ───

    /**
     * Resolve a `/media/...` path to a physical file. Returns null if not found
     * or the path escapes the content tree.
     *
     * @return array{path: string, mime: string}|null
     */
    public function resolveAsset(string $relPath): ?array
    {
        $relPath = ltrim($relPath, '/');
        if ($relPath === '' || str_contains($relPath, '..')) {
            return null;
        }

        $parts = explode('/', $relPath);
        $file  = array_pop($parts);
        if ($file === null || $file === '') {
            return null;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_EXT[$ext])) {
            return null;
        }

        // Root page: /media/~/{filename}
        if (count($parts) === 1 && $parts[0] === self::ROOT_TOKEN) {
            $dir = rtrim($this->pagesDir, '/');
        } else {
            $urlPath = '/' . implode('/', $parts);
            $page    = $this->content->find($urlPath);
            if ($page === null || !$page->filePath) {
                return null;
            }
            $dir = dirname($page->filePath);
        }

        $full = $dir . '/' . $file;
        if (!is_file($full)) {
            return null;
        }

        // Ensure the resolved file actually lives inside the content tree.
        $real = realpath($full);
        $base = realpath($this->pagesDir);
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return ['path' => $real, 'mime' => self::ALLOWED_EXT[$ext]];
    }

    // ─── Helpers ───

    private function resolvePageDir(string $pageUrlPath): string
    {
        $pageUrlPath = trim($pageUrlPath);
        if ($pageUrlPath === '' || $pageUrlPath === '/' || $pageUrlPath === self::ROOT_TOKEN) {
            return rtrim($this->pagesDir, '/');
        }
        $page = $this->content->find($pageUrlPath);
        if ($page === null || !$page->filePath) {
            throw new \RuntimeException('Page not found: ' . $pageUrlPath);
        }
        return dirname($page->filePath);
    }

    private function buildUrl(string $pageUrlPath, string $filename): string
    {
        $pageUrlPath = trim($pageUrlPath);
        if ($pageUrlPath === '' || $pageUrlPath === '/' || $pageUrlPath === self::ROOT_TOKEN) {
            return '/media/' . self::ROOT_TOKEN . '/' . $filename;
        }
        return '/media' . '/' . trim($pageUrlPath, '/') . '/' . $filename;
    }

    /**
     * Slugify the client filename and resolve collisions inside $dir
     * by appending -2, -3, … to the basename.
     */
    private function resolveFilename(string $dir, string $clientName, string $defaultExt): string
    {
        $name = Slug::filename($clientName);
        if ($name === '' || pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name = ($name === '' ? 'file' : $name) . '.' . $defaultExt;
        }

        // Force the extension to match the detected mime — avoids spoofed extensions.
        $base = pathinfo($name, PATHINFO_FILENAME);
        $name = $base . '.' . $defaultExt;

        $candidate = $name;
        $i = 2;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = $base . '-' . $i . '.' . $defaultExt;
            $i++;
        }
        return $candidate;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'Upload field was empty (UPLOAD_ERR_NO_FILE) — check post_max_size in php.ini',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory configured',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot write the uploaded file',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload',
            default               => 'Upload failed (error ' . $code . ')',
        };
    }

    private function detectMime(string $path, string $clientName): string
    {
        $mime = mime_content_type($path) ?: '';

        // mime_content_type misdetects SVG as text/plain or text/html — sniff content.
        if (!isset(self::ALLOWED_MIME[$mime]) || $mime === 'text/html' || $mime === 'text/plain') {
            $head = file_get_contents($path, false, null, 0, 512) ?: '';
            if (stripos($head, '<svg') !== false && stripos($head, 'xmlns') !== false) {
                return 'image/svg+xml';
            }
        }

        if (!isset(self::ALLOWED_MIME[$mime])) {
            $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                return 'image/svg+xml';
            }
        }

        return $mime;
    }
}

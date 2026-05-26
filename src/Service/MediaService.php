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
    public const MAX_BYTES          = 8 * 1024 * 1024;
    public const MAX_BYTES_DOCUMENT = 32 * 1024 * 1024;

    /** Image extensions → canonical MIME (used for serving). */
    public const ALLOWED_EXT = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ];

    /** Image MIMEs accepted on upload → extension. */
    private const ALLOWED_MIME = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /** Document/file extensions → canonical MIME (used for serving). */
    public const DOCUMENT_EXT = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'zip'  => 'application/zip',
        'gz'   => 'application/gzip',
        'tar'  => 'application/x-tar',
        '7z'   => 'application/x-7z-compressed',
        'rar'  => 'application/vnd.rar',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'md'   => 'text/markdown',
    ];

    /**
     * Document MIMEs accepted on upload → extension.
     * For Office formats mime_content_type() is unreliable, so we fall back
     * to the client extension (see detectDocumentExt()).
     */
    private const DOCUMENT_MIME = [
        'application/pdf'                                                          => 'pdf',
        'application/msword'                                                       => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                                                 => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.ms-powerpoint'                                            => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'                                  => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'                           => 'ods',
        'application/vnd.oasis.opendocument.presentation'                          => 'odp',
        'text/csv'                                                                 => 'csv',
        'text/plain'                                                               => 'txt',
        'application/rtf'                                                          => 'rtf',
        'application/zip'                                                          => 'zip',
        'application/gzip'                                                         => 'gz',
        'application/x-gzip'                                                       => 'gz',
        'application/x-tar'                                                        => 'tar',
        'application/x-7z-compressed'                                              => '7z',
        'application/vnd.rar'                                                      => 'rar',
        'audio/mpeg'                                                               => 'mp3',
        'audio/wav'                                                                => 'wav',
        'audio/ogg'                                                                => 'ogg',
        'video/mp4'                                                                => 'mp4',
        'video/webm'                                                               => 'webm',
        'application/json'                                                         => 'json',
        'application/xml'                                                          => 'xml',
        'text/xml'                                                                 => 'xml',
        'text/markdown'                                                            => 'md',
        // application/octet-stream is the generic binary fallback — we allow it
        // and rely on the client extension to determine the real type.
        'application/octet-stream'                                                 => '',
    ];

    public const ROOT_TOKEN       = '~';
    public const COLLECTIONS_TOKEN = '_collections';

    public function __construct(
        private readonly ContentRepository $content,
        private readonly string $pagesDir,
        private readonly ?string $collectionsDir = null,
    ) {}

    // ─── Upload ───

    /**
     * Store an uploaded file inside the directory of the given page.
     * Accepts both images and documents; the size cap and extension handling
     * differ between the two categories.
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

        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath)) {
            throw new \RuntimeException('Cannot read uploaded file');
        }

        $clientName = (string) $file->getClientFilename();
        $mime       = $this->detectMime($tmpPath, $clientName);

        if (isset(self::ALLOWED_MIME[$mime])) {
            // ── Image upload ─────────────────────────────────────────────────
            $maxBytes = self::MAX_BYTES;
            if ($file->getSize() === null || $file->getSize() > $maxBytes) {
                throw new \RuntimeException('File too large (max ' . ($maxBytes / 1024 / 1024) . ' MB for images)');
            }
            $targetDir = $this->resolvePageDir($pageUrlPath);
            // Force extension to match detected MIME — prevents extension spoofing.
            $name = $this->resolveFilename($targetDir, $clientName, self::ALLOWED_MIME[$mime]);
        } elseif ($this->isAllowedDocument($mime, $clientName)) {
            // ── Document upload ──────────────────────────────────────────────
            $maxBytes = self::MAX_BYTES_DOCUMENT;
            if ($file->getSize() === null || $file->getSize() > $maxBytes) {
                throw new \RuntimeException('File too large (max ' . ($maxBytes / 1024 / 1024) . ' MB for documents)');
            }
            $targetDir = $this->resolvePageDir($pageUrlPath);
            // Keep the client extension for documents — MIME sniffing is
            // unreliable for Office formats and the allowed-extension list gates
            // what is accepted.
            $ext  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            $name = $this->resolveFilename($targetDir, $clientName, $ext);
        } else {
            throw new \RuntimeException('Unsupported file type: ' . ($mime ?: 'unknown'));
        }

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

    // ─── Collection uploads ───

    /**
     * Store an uploaded file inside a collection item's directory.
     *
     * @return array{filename: string, url: string}
     * @throws \RuntimeException
     */
    public function storeForCollection(string $collectionName, string $itemSlug, UploadedFileInterface $file): array
    {
        if ($this->collectionsDir === null) {
            throw new \RuntimeException('CollectionsDir not configured in MediaService.');
        }

        $err = $file->getError();
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadErrorMessage($err));
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        if (!is_string($tmpPath)) {
            throw new \RuntimeException('Cannot read uploaded file');
        }

        $clientName = (string) $file->getClientFilename();
        $mime       = $this->detectMime($tmpPath, $clientName);

        if (isset(self::ALLOWED_MIME[$mime])) {
            $maxBytes = self::MAX_BYTES;
            if ($file->getSize() === null || $file->getSize() > $maxBytes) {
                throw new \RuntimeException('File too large (max ' . ($maxBytes / 1024 / 1024) . ' MB for images)');
            }
            $targetDir = rtrim($this->collectionsDir, '/') . '/' . $collectionName . '/' . $itemSlug;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $name = $this->resolveFilename($targetDir, $clientName, self::ALLOWED_MIME[$mime]);
        } elseif ($this->isAllowedDocument($mime, $clientName)) {
            $maxBytes = self::MAX_BYTES_DOCUMENT;
            if ($file->getSize() === null || $file->getSize() > $maxBytes) {
                throw new \RuntimeException('File too large (max ' . ($maxBytes / 1024 / 1024) . ' MB for documents)');
            }
            $targetDir = rtrim($this->collectionsDir, '/') . '/' . $collectionName . '/' . $itemSlug;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $ext  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            $name = $this->resolveFilename($targetDir, $clientName, $ext);
        } else {
            throw new \RuntimeException('Unsupported file type: ' . ($mime ?: 'unknown'));
        }

        $file->moveTo($targetDir . '/' . $name);

        return [
            'filename' => $name,
            'url'      => $this->urlForCollection($collectionName, $itemSlug, $name),
        ];
    }

    /**
     * Build the public URL for a collection item's asset.
     * Pattern: /media/_collections/{collection}/{slug}/{filename}
     */
    public function urlForCollection(string $collection, string $slug, string $filename): string
    {
        return '/media/' . self::COLLECTIONS_TOKEN . '/' . $collection . '/' . $slug . '/' . $filename;
    }

    /**
     * Resolve a stored asset reference for a collection item to a public URL.
     * Virtual path format: _collections/{collection}/{slug}
     */
    public function resolveCollectionRef(string $value, string $collection, string $slug): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '://') || str_starts_with($value, '/') || str_starts_with($value, '#')) {
            return $value;
        }
        return $this->urlForCollection($collection, $slug, $value);
    }

    // ─── Serving ───

    /**
     * Resolve a `/media/...` path to a physical file. Returns null if not found
     * or the path escapes the content tree.
     *
     * Handles two namespaces:
     *   /media/_collections/{collection}/{slug}/{file}  → collection item assets
     *   /media/~/{file}                                  → root page assets
     *   /media/{url-path}/{file}                         → page assets
     *
     * @return array{path: string, mime: string, download: bool}|null
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

        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::ALLOWED_EXT[$ext] ?? self::DOCUMENT_EXT[$ext] ?? null;
        if ($mime === null) {
            return null;
        }

        // Collection item asset: _collections/{collection}/{slug}/{file}
        if (count($parts) >= 3 && $parts[0] === self::COLLECTIONS_TOKEN) {
            if ($this->collectionsDir === null) {
                return null;
            }
            $collection = $parts[1];
            $slug       = $parts[2];
            $dir        = rtrim($this->collectionsDir, '/') . '/' . $collection . '/' . $slug;
            $full       = $dir . '/' . $file;
            if (!is_file($full)) {
                return null;
            }
            $real = realpath($full);
            $base = realpath($this->collectionsDir);
            if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return null;
            }
            return [
                'path'     => $real,
                'mime'     => $mime,
                'download' => !isset(self::ALLOWED_EXT[$ext]),
            ];
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

        return [
            'path'     => $real,
            'mime'     => $mime,
            'download' => !isset(self::ALLOWED_EXT[$ext]), // true for documents
        ];
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

    /**
     * Return true if the file is an allowed document type.
     * We check both the detected MIME and the client extension, because
     * mime_content_type() often returns application/octet-stream or
     * application/zip for Office Open XML files.
     */
    private function isAllowedDocument(string $mime, string $clientName): bool
    {
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        return isset(self::DOCUMENT_EXT[$ext]) || isset(self::DOCUMENT_MIME[$mime]);
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

        // SVG is misdetected as text/plain or text/html — sniff content.
        if ($mime === 'text/html' || $mime === 'text/plain' || $mime === '') {
            $head = file_get_contents($path, false, null, 0, 512) ?: '';
            if (stripos($head, '<svg') !== false && stripos($head, 'xmlns') !== false) {
                return 'image/svg+xml';
            }
        }

        // Extension fallback for SVG identified by extension even if MIME was not detected.
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME[$mime]) && $ext === 'svg') {
            return 'image/svg+xml';
        }

        // For document types mime_content_type() often returns application/octet-stream
        // or application/zip (for Office Open XML). Trust the client extension when the
        // MIME is generic and the extension is in our allow-list.
        if (
            ($mime === 'application/octet-stream' || $mime === 'application/zip' || $mime === '')
            && isset(self::DOCUMENT_EXT[$ext])
        ) {
            return self::DOCUMENT_EXT[$ext];
        }

        return $mime;
    }
}

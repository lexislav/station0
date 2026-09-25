<?php

declare(strict_types=1);

namespace Station0\Service;

/**
 * Resized image previews (thumbnails), generated lazily with GD.
 *
 * The `|thumb` Twig filter only builds a signed URL:
 *
 *   /media/gallery/photo.jpg  →  /thumb/600x0/3f9a1c2b7e4d/gallery/photo.jpg
 *                                        │     │            └─ same path as under /media/
 *                                        │     └─ HMAC(spec | path | source mtime)
 *                                        └─ {width}x{height}, "-c" = crop to fill ("cover"),
 *                                           "-webp" = convert (URL then ends in ".webp")
 *
 * The first request for that URL renders the preview into
 * `{cache}/thumbs/` and later requests just stream the file. The signature
 * stops anyone from requesting arbitrary sizes; the source mtime inside it
 * changes the URL whenever the image is replaced, so responses stay immutable.
 *
 * Static mode ($publicDir set): the preview is written to
 * `{public}/thumb/{spec}/{sig}/{path}` instead — exactly the URL path — so from
 * the second request on the web server sends it without starting PHP.
 *
 * Anything that cannot or need not be resized — external URLs, SVG, GIF,
 * documents, missing files, a size not smaller than the original, no GD —
 * is returned unchanged, so templates can apply the filter unconditionally.
 */
final class ThumbService
{
    public const MAX_SIZE = 3000;

    /** Square size of the admin editor previews (80 CSS px at 2x). */
    public const ADMIN_PREVIEW = 160;

    private const JPEG_QUALITY = 82;
    private const WEBP_QUALITY = 80;
    private const PNG_LEVEL    = 6;

    /** Upper bound the memory limit may be raised to while decoding a large source. */
    private const MEMORY_CEILING = 512 * 1024 * 1024;

    /** Resizable source MIMEs → cache file extension. GIF is skipped (animation). */
    private const RASTER = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param ?string $publicDir     web root for static mode; null = serve through PHP
     * @param ?string $defaultFormat 'webp' to convert by default; null = keep the source format
     */
    public function __construct(
        private readonly MediaService $media,
        private readonly string $cacheDir,
        private readonly string $secret,
        private readonly ?string $publicDir = null,
        private readonly ?string $defaultFormat = null,
    ) {}

    public static function supportsWebp(): bool
    {
        return function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /**
     * Read the signing key from $file, creating it on first use. The file is
     * created exclusively, so concurrent first requests agree on one key.
     */
    public static function loadOrCreateKey(string $file): string
    {
        if (is_file($file)) {
            return trim((string) file_get_contents($file));
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $key = bin2hex(random_bytes(32));
        $fh  = @fopen($file, 'x');
        if ($fh === false) {
            return trim((string) file_get_contents($file)); // another request won the race
        }
        fwrite($fh, $key . "\n");
        fclose($fh);
        @chmod($file, 0600);
        return $key;
    }

    /**
     * Delete every generated thumbnail (the cache and, when given, the static
     * copies under {public}/thumb); returns the number of files removed.
     */
    public static function purge(string $cacheDir, ?string $publicDir = null): int
    {
        $count = self::removeTree(rtrim($cacheDir, '/') . '/thumbs');
        if ($publicDir !== null) {
            $count += self::removeTree(rtrim($publicDir, '/') . '/thumb');
        }
        return $count;
    }

    private static function removeTree(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } elseif (@unlink($entry->getPathname())) {
                $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }

    // ─── URLs ───

    /**
     * Thumbnail URL for a `/media/...` image, or $src unchanged when it cannot
     * or need not be resized. $width / $height of 0 mean "unconstrained";
     * $fit 'cover' (with both sides given) crops to exactly fill the box.
     * $format 'webp' converts, 'original' keeps the source format; null uses
     * the configured default.
     */
    public function url(?string $src, int $width, int $height = 0, string $fit = 'contain', ?string $format = null): string
    {
        $src = (string) $src;
        $source = $this->source($src);
        if ($source === null) {
            return $src;
        }

        $width  = max(0, min(self::MAX_SIZE, $width));
        $height = max(0, min(self::MAX_SIZE, $height));
        $cover  = $fit === 'cover' && $width > 0 && $height > 0;
        if ($width === 0 && $height === 0) {
            return $src;
        }

        $webp = $this->wantsWebp($source['mime'], $format);
        [$sw, $sh] = $source['size'];
        $plan = self::plan($sw, $sh, $width, $height, $cover);
        if ($plan['dw'] === $sw && $plan['dh'] === $sh && !$webp) {
            return $src; // would not shrink, crop or convert anything — serve the original
        }

        $spec = $width . 'x' . $height . ($cover ? '-c' : '') . ($webp ? '-webp' : '');
        $sig  = $this->sign($spec, $source['rel'], $source['mtime']);
        return '/thumb/' . $spec . '/' . $sig . '/' . $source['rel'] . ($webp ? '.webp' : '');
    }

    /**
     * `srcset` value with one width-constrained candidate per requested width.
     * Widths at or above the original's collapse into a single entry for the
     * original itself (converted when $format asks for it). Empty string for
     * images that cannot be resized.
     *
     * @param list<int|string> $widths
     */
    public function srcset(?string $src, array $widths, ?string $format = null): string
    {
        $src    = (string) $src;
        $source = $this->source($src);
        if ($source === null) {
            return '';
        }
        $origW  = $source['size'][0];
        $widths = array_unique(array_filter(array_map('intval', $widths), fn (int $w) => $w > 0));
        sort($widths);

        $entries = [];
        foreach ($widths as $w) {
            if ($w >= $origW) {
                $entries[] = $this->url($src, $origW, 0, 'contain', $format) . ' ' . $origW . 'w';
                break;
            }
            $entries[] = $this->url($src, $w, 0, 'contain', $format) . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }

    // ─── Serving ───

    /**
     * Resolve a `/thumb/{spec}/{sig}/{path}` request to a file to send.
     * Generates the thumbnail on first use. `fallback` is true when it could
     * not be generated (e.g. too large to decode) — the caller then redirects
     * to the original. Null for malformed, unsigned or missing requests.
     *
     * @return array{path: string, mime: string, fallback: bool}|null
     */
    public function resolve(string $spec, string $sig, string $relPath): ?array
    {
        if (!preg_match('/^(\d{1,4})x(\d{1,4})(-c)?(-webp)?$/', $spec, $m) || !preg_match('/^[a-f0-9]{12}$/', $sig)) {
            return null;
        }
        [$width, $height, $cover, $webp] = [(int) $m[1], (int) $m[2], ($m[3] ?? '') !== '', isset($m[4])];
        if ($webp) {
            // Converted thumbnails carry the real extension: …/photo.jpg.webp
            if (!str_ends_with($relPath, '.webp') || !self::supportsWebp()) {
                return null;
            }
            $relPath = substr($relPath, 0, -strlen('.webp'));
        }
        if ($width > self::MAX_SIZE || $height > self::MAX_SIZE || ($width === 0 && $height === 0)
            || ($cover && ($width === 0 || $height === 0))) {
            return null;
        }

        $asset = $this->media->resolveAsset($relPath);
        if ($asset === null || !isset(self::RASTER[$asset['mime']])) {
            return null;
        }
        $rel   = ltrim($relPath, '/');
        $mtime = (int) filemtime($asset['path']);
        if (!hash_equals($this->sign($spec, $rel, $mtime), $sig)) {
            return null;
        }

        $outMime = $webp ? 'image/webp' : $asset['mime'];
        $dest    = $this->destination($spec, $sig, $rel, $mtime, $outMime);
        if ($dest === null) {
            return null;
        }
        if (is_file($dest)) {
            return ['path' => $dest, 'mime' => $outMime, 'fallback' => false];
        }

        try {
            $this->generateLocked($asset['path'], $asset['mime'], $outMime, $dest, $width, $height, $cover);
        } catch (\Throwable) {
            return ['path' => $asset['path'], 'mime' => $asset['mime'], 'fallback' => true];
        }
        return ['path' => $dest, 'mime' => $outMime, 'fallback' => false];
    }

    // ─── Geometry ───

    /**
     * Source crop box (sx, sy, sw, sh) and output size (dw, dh) for a source of
     * $srcW × $srcH. Never upscales: when the box is larger than the source
     * (or its crop), the output keeps the source's own pixel size.
     *
     * @return array{sx: int, sy: int, sw: int, sh: int, dw: int, dh: int}
     */
    public static function plan(int $srcW, int $srcH, int $width, int $height, bool $cover): array
    {
        $sx = 0;
        $sy = 0;
        $cw = $srcW;
        $ch = $srcH;

        if ($cover) {
            $ratio = $width / $height;
            if ($srcW / $srcH > $ratio) {
                $cw = max(1, (int) round($srcH * $ratio));
                $sx = intdiv($srcW - $cw, 2);
            } else {
                $ch = max(1, (int) round($srcW / $ratio));
                $sy = intdiv($srcH - $ch, 2);
            }
            $scale = min(1.0, $width / $cw);
        } elseif ($width > 0 && $height > 0) {
            $scale = min(1.0, $width / $srcW, $height / $srcH);
        } elseif ($width > 0) {
            $scale = min(1.0, $width / $srcW);
        } else {
            $scale = min(1.0, $height / $srcH);
        }

        return [
            'sx' => $sx,
            'sy' => $sy,
            'sw' => $cw,
            'sh' => $ch,
            'dw' => max(1, (int) round($cw * $scale)),
            'dh' => max(1, (int) round($ch * $scale)),
        ];
    }

    // ─── Internals ───

    /**
     * A resizable local source behind a `/media/...` URL, with its displayed
     * size (EXIF rotation applied), or null.
     *
     * @return array{rel: string, path: string, mime: string, mtime: int, size: array{0: int, 1: int}}|null
     */
    private function source(string $src): ?array
    {
        if (!extension_loaded('gd') || !str_starts_with($src, '/media/') || strpbrk($src, '?#') !== false) {
            return null;
        }
        $rel   = substr($src, strlen('/media/'));
        $asset = $this->media->resolveAsset($rel);
        if ($asset === null || !isset(self::RASTER[$asset['mime']])) {
            return null;
        }
        $info = @getimagesize($asset['path']);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        $size = [(int) $info[0], (int) $info[1]];
        if (self::orientation($asset['path'], $asset['mime']) >= 5) {
            $size = [$size[1], $size[0]]; // stored sideways, displayed rotated
        }
        return [
            'rel'   => $rel,
            'path'  => $asset['path'],
            'mime'  => $asset['mime'],
            'mtime' => (int) filemtime($asset['path']),
            'size'  => $size,
        ];
    }

    /**
     * Where a thumbnail lives: a hashed name in the cache, or — in static mode —
     * the path mirroring its URL under the web root. Null if that path would
     * leave the static directory (defense in depth; resolveAsset() already
     * rejects "..").
     */
    private function destination(string $spec, string $sig, string $rel, int $mtime, string $outMime): ?string
    {
        if ($this->publicDir === null) {
            $key = sha1($spec . '|' . $rel . '|' . $mtime);
            return rtrim($this->cacheDir, '/') . '/thumbs/' . substr($key, 0, 2) . '/' . $key . '.' . self::RASTER[$outMime];
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
        }
        $webp = str_ends_with($spec, '-webp');
        return rtrim($this->publicDir, '/') . '/thumb/' . $spec . '/' . $sig . '/' . $rel . ($webp ? '.webp' : '');
    }

    private function wantsWebp(string $sourceMime, ?string $format): bool
    {
        $format ??= $this->defaultFormat;
        return $format === 'webp' && $sourceMime !== 'image/webp' && self::supportsWebp();
    }

    private function sign(string $spec, string $rel, int $mtime): string
    {
        return substr(hash_hmac('sha256', $spec . '|' . $rel . '|' . $mtime, $this->secret), 0, 12);
    }

    /** Generate under an exclusive lock so parallel requests render each thumbnail once. */
    private function generateLocked(string $src, string $mime, string $outMime, string $dest, int $width, int $height, bool $cover): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create thumbnail directory');
        }
        $lockPath = $dest . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open thumbnail lock');
        }
        try {
            flock($lock, LOCK_EX);
            if (!is_file($dest)) {
                $this->generate($src, $mime, $outMime, $dest, $width, $height, $cover);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    private function generate(string $src, string $mime, string $outMime, string $dest, int $width, int $height, bool $cover): void
    {
        $info = getimagesize($src);
        if ($info === false) {
            throw new \RuntimeException('Unreadable image');
        }
        $this->ensureMemory((int) $info[0] * (int) $info[1]);

        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => @imagecreatefromwebp($src),
        };
        if ($img === false) {
            throw new \RuntimeException('Cannot decode image');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $img = self::applyOrientation($img, self::orientation($src, $mime));

        $p   = self::plan(imagesx($img), imagesy($img), $width, $height, $cover);
        $out = imagecreatetruecolor($p['dw'], $p['dh']);
        if ($outMime !== 'image/jpeg') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $img, 0, 0, $p['sx'], $p['sy'], $p['dw'], $p['dh'], $p['sw'], $p['sh']);
        unset($img);

        $tmp = $dest . '.tmp.' . bin2hex(random_bytes(4));
        $ok  = match ($outMime) {
            'image/jpeg' => imageinterlace($out, true) !== false && imagejpeg($out, $tmp, self::JPEG_QUALITY),
            'image/png'  => imagepng($out, $tmp, self::PNG_LEVEL),
            'image/webp' => imagewebp($out, $tmp, self::WEBP_QUALITY),
        };
        unset($out);
        if (!$ok || !rename($tmp, $dest)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write thumbnail');
        }
    }

    /**
     * Decoding needs ~5 bytes per source pixel (plus the output). Raise the
     * memory limit when needed, up to MEMORY_CEILING; beyond that give up and
     * let the caller fall back to the original.
     */
    private function ensureMemory(int $pixels): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit < 0) {
            return;
        }
        $needed = memory_get_usage() + $pixels * 5 + 16 * 1024 * 1024;
        if ($needed <= $limit) {
            return;
        }
        if ($needed > self::MEMORY_CEILING || ini_set('memory_limit', (string) $needed) === false) {
            throw new \RuntimeException('Image too large to resize');
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $num = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g'     => $num * 1024 ** 3,
            'm'     => $num * 1024 ** 2,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /** EXIF orientation tag (1–8) of a JPEG; 1 when absent or unreadable. */
    private static function orientation(string $path, string $mime): int
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path, 'IFD0');
        $o    = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    private static function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        // imagerotate() turns counter-clockwise; EXIF 6 needs 90° clockwise.
        $angle = match ($orientation) {
            3, 4    => 180,
            5, 6    => -90,
            7, 8    => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                unset($img);
                $img = $rotated;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        return $img;
    }
}

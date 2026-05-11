<?php

declare(strict_types=1);

namespace Station0\Support;

final class Slug
{
    public static function sanitize(string $value): string
    {
        $value = self::toAscii(trim($value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return trim($value, '-_');
    }

    private static function toAscii(string $value): string
    {
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $out = $tr->transliterate($value);
                if ($out !== false) {
                    return $out;
                }
            }
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $ascii !== false ? $ascii : $value;
    }

    public static function fromTitle(string $title): string
    {
        return self::sanitize($title);
    }

    /**
     * Slugify an uploaded filename while preserving its extension.
     * "Team Photo.JPG" → "team-photo.jpg"
     */
    public static function filename(string $name): string
    {
        $name = basename($name);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = self::fromTitle($base);
        if ($base === '') {
            $base = 'file';
        }
        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}

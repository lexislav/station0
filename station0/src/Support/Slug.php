<?php

declare(strict_types=1);

namespace Station0\Support;

final class Slug
{
    public static function sanitize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return trim($value, '-_');
    }

    public static function fromTitle(string $title): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        return self::sanitize($ascii !== false ? $ascii : $title);
    }
}

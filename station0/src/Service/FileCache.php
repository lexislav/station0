<?php

declare(strict_types=1);

namespace Station0\Service;

final class FileCache
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function set(string $key, string $value): void
    {
        $path = $this->path($key);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $value);
        rename($tmp, $path);
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function flush(): int
    {
        $count = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
    }
}

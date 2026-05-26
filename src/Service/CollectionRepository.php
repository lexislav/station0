<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\Slug;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Manages flat-file Collections — headless content stores with no public URL.
 *
 * Directory layout:
 *   site/content/collections/
 *     banners/
 *       _collection.yaml      ← optional schema + label
 *       summer-sale/
 *         item.txt            ← same Kirby front matter as Page files
 *         bg.jpg              ← page-local assets (same pattern as pages)
 *       winter-offer/
 *         item.txt
 *     shared-blocks/
 *       _collection.yaml
 *       hero-cta/
 *         item.txt
 *
 * _collection.yaml format:
 *   label: Banners
 *   fields:
 *     subtitle:
 *       type: text
 *       label: Subtitle
 *     cta_url:
 *       type: text
 *       label: CTA URL
 *     image:
 *       type: image
 *       label: Background Image
 */
final class CollectionRepository
{
    private array $parsedCache = [];

    public function __construct(private readonly string $collectionsDir)
    {
        if (!is_dir($this->collectionsDir)) {
            @mkdir($this->collectionsDir, 0775, true);
        }
    }

    // ─────────────────── Collections ───────────────────

    /**
     * Return all collection names (directory names inside collectionsDir),
     * each with its parsed _collection.yaml meta.
     *
     * @return list<array{name: string, label: string, schema: array}>
     */
    public function collections(): array
    {
        $result = [];
        foreach (glob(rtrim($this->collectionsDir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name   = basename($dir);
            $schema = $this->schema($name);
            $result[] = [
                'name'   => $name,
                'label'  => $schema['label'] ?? $this->labelFromName($name),
                'schema' => $schema,
                'count'  => count($this->items($name, true)),
            ];
        }
        return $result;
    }

    /**
     * Parse _collection.yaml for a given collection.
     * Returns an empty array if the file doesn't exist or can't be parsed.
     */
    public function schema(string $name): array
    {
        $file = $this->collectionDir($name) . '/_collection.yaml';
        if (!is_file($file)) {
            return [];
        }
        try {
            $parsed = Yaml::parseFile($file);
            return is_array($parsed) ? $parsed : [];
        } catch (ParseException) {
            return [];
        }
    }

    /**
     * Save a _collection.yaml schema definition.
     */
    public function saveSchema(string $name, array $schema): void
    {
        $dir = $this->collectionDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/_collection.yaml', Yaml::dump($schema, 4));
    }

    /**
     * Create a new collection directory (with optional label).
     */
    public function createCollection(string $name, string $label = ''): void
    {
        $name = Slug::sanitize($name);
        $dir  = $this->collectionDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if ($label !== '') {
            $this->saveSchema($name, ['label' => $label]);
        }
    }

    // ─────────────────── Items ───────────────────

    /**
     * All items in a collection, sorted by (sort, title).
     *
     * @return list<CollectionItem>
     */
    public function items(string $name, bool $includeUnpublished = false): array
    {
        $dir   = $this->collectionDir($name);
        $items = [];

        foreach (glob(rtrim($dir, '/') . '/*/item.txt') ?: [] as $file) {
            $item = $this->buildItem($name, $file);
            if (!$includeUnpublished && !$item->isLive()) {
                continue;
            }
            $items[] = $item;
        }

        usort($items, function (CollectionItem $a, CollectionItem $b) {
            $sa = $a->sort ?? PHP_INT_MAX;
            $sb = $b->sort ?? PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp(strtolower($a->title), strtolower($b->title));
        });

        return $items;
    }

    /**
     * Find a single item by collection name + slug.
     */
    public function find(string $name, string $slug): ?CollectionItem
    {
        $file = $this->collectionDir($name) . '/' . $slug . '/item.txt';
        if (!is_file($file)) {
            return null;
        }
        return $this->buildItem($name, $file);
    }

    /**
     * Save an item. Pass $targetFilePath when creating.
     */
    public function save(CollectionItem $item, ?string $targetFilePath = null): void
    {
        $filePath = $targetFilePath ?? $item->filePath;
        if (!$filePath) {
            throw new \RuntimeException('No filePath for CollectionItem save.');
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $content = $this->serialize($item);
        $tmp     = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        rename($tmp, $filePath);

        unset($this->parsedCache[$filePath]);
    }

    /**
     * Delete an item and its directory (assets travel with it).
     */
    public function delete(string $name, string $slug): bool
    {
        $item = $this->find($name, $slug);
        if ($item === null || !$item->filePath) {
            return false;
        }

        $deleted = @unlink($item->filePath);
        unset($this->parsedCache[$item->filePath]);

        if ($deleted) {
            $dir = dirname($item->filePath);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $entry) {
                    if (is_file($entry)) {
                        @unlink($entry);
                    }
                }
                $remaining = array_filter(
                    glob($dir . '/{*,.*}', GLOB_BRACE) ?: [],
                    fn (string $f) => !in_array(basename($f), ['.', '..', '.DS_Store'], true),
                );
                if (empty($remaining)) {
                    @rmdir($dir);
                }
            }
        }

        return $deleted;
    }

    /**
     * Rename an item's slug (renames its directory).
     */
    public function rename(CollectionItem $item, string $newSlug): void
    {
        $newSlug = Slug::sanitize($newSlug);
        if ($newSlug === '' || $newSlug === $item->slug) {
            return;
        }

        $oldDir    = dirname($item->filePath);
        $parentDir = dirname($oldDir);
        $newDir    = $parentDir . '/' . $newSlug;

        if (is_dir($newDir)) {
            throw new \RuntimeException("An item with slug '{$newSlug}' already exists in '{$item->collection}'.");
        }

        if (!@rename($oldDir, $newDir)) {
            throw new \RuntimeException('Failed to rename collection item directory.');
        }

        $item->slug     = $newSlug;
        $item->filePath = $newDir . '/item.txt';

        foreach (array_keys($this->parsedCache) as $key) {
            if (str_starts_with($key, $oldDir . '/')) {
                unset($this->parsedCache[$key]);
            }
        }
    }

    /** File mtime for cache invalidation. */
    public function mtime(string $name, string $slug): int
    {
        $item = $this->find($name, $slug);
        return ($item && $item->filePath && is_file($item->filePath))
            ? (int) filemtime($item->filePath)
            : 0;
    }

    /**
     * Return the filesystem directory for a collection item's assets.
     * Used by MediaService and UploadController.
     */
    public function itemDir(string $name, string $slug): string
    {
        return $this->collectionDir($name) . '/' . $slug;
    }

    /**
     * Derive the virtual URL path used for media resolution.
     * Matches the _collections/ prefix detected by MediaService.
     */
    public static function virtualPath(string $name, string $slug): string
    {
        return '_collections/' . $name . '/' . $slug;
    }

    // ─────────────────── Internals ───────────────────

    private function collectionDir(string $name): string
    {
        return rtrim($this->collectionsDir, '/') . '/' . $name;
    }

    private function buildItem(string $collectionName, string $filePath): CollectionItem
    {
        [$meta, $body] = $this->parseFrontMatterFromFile($filePath);
        $slug = basename(dirname($filePath));

        $item = new CollectionItem(
            collection: $collectionName,
            slug:       $slug,
            title:      (string) ($meta['title'] ?? $slug),
            body:       $body,
            published:  filter_var($meta['published'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            sort:       isset($meta['sort']) && $meta['sort'] !== '' ? (int) $meta['sort'] : null,
            extra:      array_diff_key($meta, array_flip(['title', 'published', 'sort'])),
        );
        $item->filePath = $filePath;
        return $item;
    }

    // ─────────────────── Front matter (same as ContentRepository) ───────────────────

    private function parseFrontMatterFromFile(string $filePath): array
    {
        if (isset($this->parsedCache[$filePath])) {
            return $this->parsedCache[$filePath];
        }
        $raw    = @file_get_contents($filePath);
        $result = $raw !== false ? $this->parseFrontMatter($raw) : [[], ''];
        $this->parsedCache[$filePath] = $result;
        return $result;
    }

    private function parseFrontMatter(string $raw): array
    {
        $raw   = str_replace("\r\n", "\n", $raw);
        $lines = explode("\n", $raw);
        $meta  = [];
        $sepIdx = null;

        foreach ($lines as $i => $line) {
            if (rtrim($line) === '---') {
                $sepIdx = $i;
                break;
            }
        }

        if ($sepIdx !== null) {
            foreach (array_slice($lines, 0, $sepIdx) as $line) {
                if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*)$/', $line, $m)) {
                    $meta[strtolower($m[1])] = rtrim($m[2]);
                }
            }
            $body = ltrim(implode("\n", array_slice($lines, $sepIdx + 1)), "\n ");
        } else {
            $body = $raw;
        }

        return [$meta, $body];
    }

    // ─────────────────── Serialization ───────────────────

    private function serialize(CollectionItem $item): string
    {
        $fields = [
            'Title'     => $item->title,
            'Published' => $item->published ? 'true' : 'false',
            'Sort'      => $item->sort !== null ? (string) $item->sort : null,
        ];

        foreach ($item->extra as $k => $v) {
            $fields[ucfirst($k)] = $v;
        }

        $lines = [];
        foreach ($fields as $key => $val) {
            if ($val !== null && $val !== '') {
                $lines[] = "{$key}: {$val}";
            }
        }

        return implode("\n", $lines) . "\n---\n\n" . rtrim($item->body) . "\n";
    }

    // ─────────────────── Helpers ───────────────────

    private function labelFromName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}

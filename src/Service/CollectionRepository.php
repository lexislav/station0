<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\FieldSchema;
use Station0\Support\FrontMatter;
use Station0\Support\Placeholders;
use Station0\Support\Slug;
use Station0\Support\Visibility;
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
 *
 * Optional computed title (see titleSpec()):
 *   title: "{name} {surname}"           # shorthand, input: readonly
 *   title:
 *     from: "{brand} – {model}"
 *     input: editable                   # editable | readonly | hidden
 */
final class CollectionRepository
{
    /** Front-matter keys mapped to CollectionItem properties. */
    public const RESERVED_KEYS = ['title', 'published', 'sort', 'status', 'publishat', 'expireat'];

    /** Title input modes of a computed title; the first is the default. */
    public const TITLE_INPUTS = ['readonly', 'editable', 'hidden'];

    /** Visibility keys a collection schema may claim as its own fields → their front-matter spelling. */
    private const VISIBILITY_KEYS = ['status' => 'Status', 'publishat' => 'PublishAt', 'expireat' => 'ExpireAt'];

    private array $parsedCache = [];

    /** @var array<string, list<string>> collection → visibility keys claimed by its schema */
    private array $claimedCache = [];

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
        foreach ($this->names() as $name) {
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
     * Collection names only — no item parsing (cheap enough for the admin nav).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('basename', glob(rtrim($this->collectionsDir, '/') . '/*', GLOB_ONLYDIR) ?: []);
    }

    public function directory(): string
    {
        return $this->collectionsDir;
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
     * The collection's computed title, normalized from the schema's `title`
     * key: a string is the pattern (`from`) with the default input mode,
     * a dict gives `from` and `input`. No (or an empty) pattern ⇒ null, the
     * title is typed by hand as before. `fields` lists the placeholders.
     *
     * @return array{from: string, input: string, fields: list<string>}|null
     */
    public function titleSpec(string $name): ?array
    {
        $raw   = $this->schema($name)['title'] ?? null;
        $from  = is_array($raw) ? ($raw['from'] ?? null) : $raw;
        $input = is_array($raw) ? strtolower(trim((string) ($raw['input'] ?? ''))) : '';
        if (!is_string($from) || trim($from) === '') {
            return null;
        }
        return [
            'from'   => trim($from),
            'input'  => in_array($input, self::TITLE_INPUTS, true) ? $input : self::TITLE_INPUTS[0],
            'fields' => Placeholders::names($from),
        ];
    }

    /**
     * The title the collection's pattern builds from the item's fields,
     * `{slug}` and `{collection}`; null when the collection has no pattern.
     */
    public function computeTitle(CollectionItem $item): ?string
    {
        $spec = $this->titleSpec($item->collection);
        return $spec === null ? null : $this->fillTitle($spec['from'], $item->extra, $item->slug, $item->collection);
    }

    /**
     * Whether the item's title is what the pattern builds — false for a
     * title written by hand in `editable` mode (an override).
     */
    public function titleInSync(CollectionItem $item): bool
    {
        $computed = $this->computeTitle($item);
        return $computed === null || $computed === $item->title;
    }

    /**
     * Save a _collection.yaml schema definition.
     */
    public function saveSchema(string $name, array $schema): void
    {
        unset($this->claimedCache[$name]);
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
        $file = $this->collectionDir($name) . '/' . Slug::sanitize($slug) . '/item.txt';
        if (!is_file($file)) {
            return null;
        }
        return $this->buildItem($name, $file);
    }

    /**
     * Save an item. A new item (no filePath) goes to <collection>/<slug>/item.txt
     * unless $targetFilePath says otherwise.
     */
    public function save(CollectionItem $item, ?string $targetFilePath = null): void
    {
        $filePath = $targetFilePath ?? $item->filePath;
        if (!$filePath) {
            if ($item->collection === '' || Slug::sanitize($item->slug) === '') {
                throw new \RuntimeException('No filePath for CollectionItem save.');
            }
            $filePath = $this->itemDir($item->collection, $item->slug) . '/item.txt';
        }

        $this->applyComputedTitle($item, $filePath);

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $content = $this->serialize($item);
        $tmp     = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        rename($tmp, $filePath);

        $item->filePath = $filePath;
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
        return $this->collectionDir($name) . '/' . Slug::sanitize($slug);
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
        // Sanitize — `$name` may originate from a route parameter. Slug::sanitize
        // strips path separators and dots, so traversal is impossible.
        return rtrim($this->collectionsDir, '/') . '/' . Slug::sanitize($name);
    }

    private function buildItem(string $collectionName, string $filePath): CollectionItem
    {
        [$meta, $body] = $this->parseFrontMatterFromFile($filePath);
        $slug = basename(dirname($filePath));

        // A schema field named e.g. `status` stays a schema field; visibility
        // then falls back to the Published flag for that collection.
        $claimed    = $this->claimedVisibilityKeys($collectionName);
        $visibility = array_diff_key($meta, array_flip($claimed));

        $item = new CollectionItem(
            collection: $collectionName,
            slug:       $slug,
            title:      (string) ($meta['title'] ?? $slug),
            body:       $body,
            sort:       isset($meta['sort']) && $meta['sort'] !== '' ? (int) $meta['sort'] : null,
            extra:      array_diff_key($meta, array_flip(array_diff(self::RESERVED_KEYS, $claimed))),
            status:     Visibility::statusFromMeta($visibility),
            publishAt:  Visibility::datetimeFromMeta($visibility, 'publishat'),
            expireAt:   Visibility::datetimeFromMeta($visibility, 'expireat'),
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
        $result = $raw !== false ? FrontMatter::parse($raw) : [[], ''];
        $this->parsedCache[$filePath] = $result;
        return $result;
    }

    // ─────────────────── Serialization ───────────────────

    private function serialize(CollectionItem $item): string
    {
        $fields = [
            'Title'     => $item->title,
            'Status'    => $item->status,
            // Mirror of Status for pre-0.9 readers.
            'Published' => $item->isPublished() ? 'true' : 'false',
            'PublishAt' => $item->publishAt,
            'ExpireAt'  => $item->expireAt,
            'Sort'      => $item->sort !== null ? (string) $item->sort : null,
        ];
        // Keys the schema claims carry the schema field's value (from extra).
        foreach ($this->claimedVisibilityKeys($item->collection) as $key) {
            unset($fields[self::VISIBILITY_KEYS[$key]]);
        }

        foreach ($item->extra as $k => $v) {
            $fields[ucfirst($k)] = $v;
        }

        return FrontMatter::serialize($fields, $item->body);
    }

    // ─────────────────── Computed title ───────────────────

    /**
     * Sets the item's title from the collection's pattern before a save.
     *
     * `readonly` / `hidden`: always the computed title — a posted one is
     * ignored. `editable`: an empty title, or one left as it was while it
     * still matched the pattern, follows the fields; anything else was
     * written by hand and is kept (an override). The stored file is the
     * reference, so this works for the admin form and for tasks alike.
     * An empty result keeps the current title (or the slug).
     */
    private function applyComputedTitle(CollectionItem $item, string $filePath): void
    {
        $spec = $this->titleSpec($item->collection);
        if ($spec === null) {
            return;
        }
        $computed = $this->fillTitle($spec['from'], $item->extra, $item->slug, $item->collection);
        $title    = trim($item->title);

        if ($spec['input'] === 'editable' && $title !== '') {
            $previous = $this->storedTitleState($spec['from'], $item, $filePath);
            $followed = $previous !== null && $previous['inSync'] && $title === $previous['title'];
            if (!$followed) {
                $item->title = $title;
                return;
            }
        }

        $item->title = $computed !== '' ? $computed : ($title !== '' ? $title : $item->slug);
    }

    /**
     * Title stored in $filePath and whether it matched the pattern then.
     *
     * @return array{title: string, inSync: bool}|null null for a new item
     */
    private function storedTitleState(string $from, CollectionItem $item, string $filePath): ?array
    {
        $raw = is_file($filePath) ? @file_get_contents($filePath) : false;
        if ($raw === false) {
            return null;
        }
        [$meta] = FrontMatter::parse($raw);
        $title  = trim((string) ($meta['title'] ?? ''));
        unset($meta['title']);
        return [
            'title'  => $title,
            'inSync' => $title === $this->fillTitle($from, $meta, basename(dirname($filePath)), $item->collection),
        ];
    }

    /** @param array<string, mixed> $fields */
    private function fillTitle(string $from, array $fields, string $slug, string $collection): string
    {
        return Placeholders::fill($from, array_replace(
            ['slug' => $slug, 'collection' => $collection],
            array_change_key_case($fields, CASE_LOWER),
        ));
    }

    // ─────────────────── Helpers ───────────────────

    /**
     * Visibility keys (status, publishat, expireat) that the collection's
     * schema declares as its own fields.
     *
     * @return list<string>
     */
    private function claimedVisibilityKeys(string $name): array
    {
        if ($name === '') {
            return [];
        }
        if (!isset($this->claimedCache[$name])) {
            $names = array_map(
                fn (array $f): string => strtolower((string) ($f['name'] ?? '')),
                FieldSchema::normalize($this->schema($name)['fields'] ?? []),
            );
            $this->claimedCache[$name] = array_values(array_intersect(array_keys(self::VISIBILITY_KEYS), $names));
        }
        return $this->claimedCache[$name];
    }

    private function labelFromName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}

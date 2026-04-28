<?php

declare(strict_types=1);

namespace Station0\Service;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a page body that is stored as a YAML list of typed blocks.
 *
 * Asset references inside blocks are stored *relative to the page* —
 * a bare filename like "team-photo.jpg" — and resolved at render time
 * to "/media/{page-url-path}/team-photo.jpg". This keeps stored content
 * stable across page moves: the directory rename takes the assets with it,
 * and the references never had an absolute path baked in.
 *
 * Block format:
 *
 *   - type: text
 *     body: |
 *       # Markdown content
 *
 *   - type: gallery
 *     columns: 3
 *     images:
 *       - src: photo.jpg
 *         alt: Photo
 */
final class PageRenderer
{
    public function __construct(
        private readonly MarkdownConverter $converter,
        private readonly BlockRegistry $blocks,
        private readonly FileCache $cache,
        private readonly MediaService $media,
    ) {}

    public function render(Page $page, int $sourceMtime): string
    {
        $key = 'page:' . sha1($page->filePath) . ':' . $sourceMtime;
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $html = $this->renderBody($page->body, $page->urlPath);
        $this->cache->set($key, $html);
        return $html;
    }

    /** @return list<array<string, mixed>> */
    public function parseBlocks(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        try {
            $data = Yaml::parse($body);
            if (is_array($data) && array_is_list($data) && isset($data[0]['type'])) {
                return $data;
            }
        } catch (ParseException) {}

        // Legacy / plain markdown → treat as a single text block
        return [['type' => 'text', 'body' => $body]];
    }

    private function renderBody(string $body, string $pageUrlPath): string
    {
        $html = '';
        foreach ($this->parseBlocks($body) as $block) {
            $html .= $this->renderBlock($block, $pageUrlPath);
        }
        return $html;
    }

    /** @param array<string, mixed> $block */
    private function renderBlock(array $block, string $pageUrlPath): string
    {
        $type = $block['type'] ?? 'text';

        if ($type === 'text') {
            $body = (string) ($block['body'] ?? '');
            $body = $this->rewriteMarkdownAssets($body, $pageUrlPath);
            return $this->converter->convert($body)->getContent();
        }

        if ($this->blocks->exists($type)) {
            $resolved = $this->resolveBlockAssets($block, $pageUrlPath);
            return $this->blocks->render($type, $resolved);
        }

        return '<!-- unknown block type: ' . htmlspecialchars($type) . ' -->';
    }

    /**
     * Walk a block's data using its schema and resolve any value bound to
     * an `image` field (including images nested inside list items) from
     * a bare filename to a /media/... URL.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function resolveBlockAssets(array $block, string $pageUrlPath): array
    {
        $schema = $this->blocks->schema((string) $block['type']);
        if (!$schema) {
            return $block;
        }
        $fields = $this->normalizeFields($schema['fields'] ?? []);
        return $this->applyFieldResolution($block, $fields, $pageUrlPath);
    }

    /**
     * Convert dict-form schema fields ({name: {...}}) to list form so we can
     * iterate; mirrors PageController::normalizeFields().
     *
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeFields(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $def) {
            $field         = is_array($def) ? $def : [];
            $field['name'] = is_string($name) ? $name : (string) ($field['name'] ?? '');
            if (isset($field['item']) && is_array($field['item'])) {
                $field['item_fields'] = $this->normalizeFields($field['item']);
                unset($field['item']);
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * @param array<string, mixed>          $data
     * @param list<array<string, mixed>>    $fields
     * @return array<string, mixed>
     */
    private function applyFieldResolution(array $data, array $fields, string $pageUrlPath): array
    {
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !array_key_exists($name, $data)) {
                continue;
            }

            if ($type === 'image' && is_string($data[$name])) {
                $data[$name] = $this->media->resolveRef($data[$name], $pageUrlPath);
                continue;
            }

            if ($type === 'list' && is_array($data[$name])) {
                $itemFields = $field['item_fields'] ?? [];
                if (!is_array($itemFields)) {
                    continue;
                }
                foreach ($data[$name] as $i => $item) {
                    if (is_array($item)) {
                        $data[$name][$i] = $this->applyFieldResolution($item, $itemFields, $pageUrlPath);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Rewrite markdown image references (![alt](src)) so that bare filenames
     * resolve against the current page. Absolute URLs and external URLs are
     * left untouched.
     */
    private function rewriteMarkdownAssets(string $markdown, string $pageUrlPath): string
    {
        // Matches ![alt](src "optional title")
        $pattern = '/(!\[[^\]]*\])\(\s*([^)\s]+)((?:\s+"[^"]*")?\s*)\)/';
        return (string) preg_replace_callback($pattern, function (array $m) use ($pageUrlPath) {
            $resolved = $this->media->resolveRef($m[2], $pageUrlPath);
            return $m[1] . '(' . $resolved . $m[3] . ')';
        }, $markdown);
    }
}

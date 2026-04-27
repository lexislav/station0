<?php

declare(strict_types=1);

namespace Station0\Service;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a page body that is stored as a YAML list of typed blocks.
 * Falls back to plain-Markdown rendering for legacy pages.
 *
 * Block format (stored in .txt body):
 *
 *   - type: text
 *     body: |
 *       # Markdown content here
 *
 *   - type: gallery
 *     columns: 3
 *     images:
 *       - src: /uploads/photo.jpg
 *         alt: Photo
 */
final class PageRenderer
{
    public function __construct(
        private readonly MarkdownConverter $converter,
        private readonly BlockRegistry $blocks,
        private readonly FileCache $cache,
    ) {}

    public function render(Page $page, int $sourceMtime): string
    {
        $key = 'page:' . sha1($page->filePath) . ':' . $sourceMtime;
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $html = $this->renderBody($page->body);
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

    private function renderBody(string $body): string
    {
        $html = '';
        foreach ($this->parseBlocks($body) as $block) {
            $html .= $this->renderBlock($block);
        }
        return $html;
    }

    /** @param array<string, mixed> $block */
    private function renderBlock(array $block): string
    {
        $type = $block['type'] ?? 'text';

        if ($type === 'text') {
            return $this->converter->convert($block['body'] ?? '')->getContent();
        }

        if ($this->blocks->exists($type)) {
            return $this->blocks->render($type, $block);
        }

        return '<!-- unknown block type: ' . htmlspecialchars($type) . ' -->';
    }
}

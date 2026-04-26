<?php

declare(strict_types=1);

namespace Station0\Service;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class MarkdownRenderer
{
    public function __construct(
        private readonly MarkdownConverter $converter,
        private readonly FileCache $cache,
        private readonly BlockRegistry $blocks,
    ) {}

    public function render(Page $page, int $sourceMtime): string
    {
        $key = 'md:' . sha1($page->filePath) . ':' . $sourceMtime;
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $html = $this->parseAndRender($page->body);
        $this->cache->set($key, $html);
        return $html;
    }

    private function parseAndRender(string $body): string
    {
        // Split body into alternating [text, type, yaml, text, type, yaml, ...] chunks.
        // Block syntax: a line starting with :::type, followed by YAML content, closed by a line with :::
        $parts = preg_split(
            '/^:::(\w[\w-]*)[^\n]*\n(.*?)^:::\s*\n?/ms',
            $body,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [$body];

        $html = '';
        $i    = 0;
        $len  = count($parts);

        while ($i < $len) {
            $text = $parts[$i++];
            if (trim($text) !== '') {
                $html .= $this->converter->convert($text)->getContent();
            }

            if ($i >= $len) {
                break;
            }

            $type    = $parts[$i++];
            $content = $parts[$i++] ?? '';

            if ($this->blocks->exists($type)) {
                try {
                    $data = Yaml::parse($content);
                } catch (ParseException) {
                    $data = [];
                }
                $html .= $this->blocks->render($type, is_array($data) ? $data : []);
            } else {
                // Unknown block type — show raw so the author notices
                $html .= '<pre><code class="block-unknown">'
                       . htmlspecialchars(trim($content))
                       . '</code></pre>';
            }
        }

        return $html;
    }
}

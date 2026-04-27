<?php

declare(strict_types=1);

namespace Station0\Service;

use Slim\Views\Twig;
use Symfony\Component\Yaml\Yaml;

final class BlockRegistry
{
    /** @var array<string, array<string, mixed>|null> */
    private array $schemas = [];

    public function __construct(
        private readonly string $blocksPath,
        private readonly Twig $twig,
    ) {}

    /** @return string[] */
    public function available(): array
    {
        $files = glob($this->blocksPath . '/*/schema.yaml') ?: [];
        return array_map(fn (string $f) => basename(dirname($f)), $files);
    }

    /** @return array<string, mixed>|null */
    public function schema(string $type): ?array
    {
        if (!array_key_exists($type, $this->schemas)) {
            $file = $this->blocksPath . '/' . $type . '/schema.yaml';
            $this->schemas[$type] = file_exists($file) ? Yaml::parseFile($file) : null;
        }
        return $this->schemas[$type];
    }

    public function exists(string $type): bool
    {
        return file_exists($this->blocksPath . '/' . $type . '/template.twig');
    }

    /** @param array<string, mixed> $data */
    public function render(string $type, array $data): string
    {
        try {
            return $this->twig->fetch('blocks/' . $type . '/template.twig', ['block' => $data]);
        } catch (\Throwable) {
            return '<!-- block:' . htmlspecialchars($type) . ' render error -->';
        }
    }

    /**
     * Generates a starter YAML snippet for inserting a block into content,
     * including the opening/closing ::: markers.
     */
    public function snippet(string $type): string
    {
        $schema = $this->schema($type);
        $yaml   = $schema !== null ? $this->buildSnippetYaml($schema['fields'] ?? [], 0) : '';
        return ':::' . $type . "\n" . $yaml . ':::';
    }

    /** @param list<array<string, mixed>> $fields */
    private function buildSnippetYaml(array $fields, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $out = '';

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'] ?? 'text';

            if ($type === 'list') {
                $out       .= $pad . $name . ":\n";
                $subFields  = $field['item_fields'] ?? [];
                if (!empty($subFields)) {
                    $first = true;
                    foreach ($subFields as $sub) {
                        $prefix = $first ? $pad . '  - ' : $pad . '    ';
                        $out   .= $prefix . $sub['name'] . ": \n";
                        $first  = false;
                    }
                } else {
                    $out .= $pad . "  - \n";
                }
            } elseif ($type === 'select') {
                $default = $field['default'] ?? ($field['options'][0] ?? '');
                $out .= $pad . $name . ': ' . $default . "\n";
            } elseif ($type === 'boolean') {
                $raw     = $field['default'] ?? false;
                $default = ($raw === true || $raw === 'true') ? 'true' : 'false';
                $out    .= $pad . $name . ': ' . $default . "\n";
            } else {
                $default = $field['default'] ?? '';
                $out    .= $pad . $name . ': ' . $default . "\n";
            }
        }

        return $out;
    }
}

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

    /**
     * Build a block-data array for $type populated with its schema's default
     * field values — the same starting structure a freshly added block of this
     * type has in the editor. Used to pre-seed new pages with default blocks.
     *
     * Reuses the per-field default resolution shared with {@see snippet()}, so
     * a seeded block matches a manually added one of the same type.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $type): array
    {
        $block  = ['type' => $type];
        $schema = $this->schema($type);
        foreach (($schema['fields'] ?? []) as $name => $def) {
            if (!is_string($name) || !is_array($def)) {
                continue;
            }
            $block[$name] = $this->fieldDefault($def);
        }
        return $block;
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
            } elseif ($type === 'boolean') {
                $out .= $pad . $name . ': ' . ($this->fieldDefault($field) ? 'true' : 'false') . "\n";
            } else {
                $out .= $pad . $name . ': ' . $this->fieldDefault($field) . "\n";
            }
        }

        return $out;
    }

    /**
     * Resolve a single field's default value from its schema definition.
     * The one source of truth for "what does an empty block field start as",
     * shared by {@see defaults()} and {@see buildSnippetYaml()}.
     *
     * @param  array<string, mixed> $field
     * @return mixed  string for scalars, bool for booleans, [] for lists.
     */
    private function fieldDefault(array $field): mixed
    {
        return match ((string) ($field['type'] ?? 'text')) {
            'list'    => [],
            'boolean' => ($field['default'] ?? false) === true
                         || ($field['default'] ?? null) === 'true',
            'select'  => $field['default'] ?? ($field['options'][0] ?? ''),
            default   => $field['default'] ?? '',
        };
    }
}

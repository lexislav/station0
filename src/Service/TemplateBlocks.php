<?php

declare(strict_types=1);

namespace Station0\Service;

use Station0\Support\FieldSchema;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the per-template block configuration declared in an optional
 * manifest that sits beside each template file:
 *
 *   site/templates/<template>.twig         ← the template
 *   site/templates/<template>.blocks.yaml  ← its block manifest (optional)
 *
 * Manifest format (all keys optional):
 *
 *   fields:               # page-level fields (same schema format as blocks)
 *     subtitle:
 *       type: text
 *       label: Subtitle
 *   blocks: false         # hide the page builder (fields-only template)
 *   allowedBlocks:        # restrict the "+ Add block" palette to these types
 *     - text
 *     - gallery
 *   defaultBlocks:        # pre-insert these into a new page of this template
 *     - gallery
 *
 * This is the block-level analogue of a page's AllowedChildTemplates
 * (which restricts which *templates* a child page may use); here we restrict
 * which *block types* a page's body may contain, keyed by template.
 */
final class TemplateBlocks
{
    /**
     * Front-matter keys owned by Page itself (plus `body`). A page field may not
     * use one of these names — it would clobber the page's own metadata.
     */
    public const RESERVED_FIELD_NAMES = [
        'title', 'metatitle', 'published', 'publishedat', 'author', 'updated',
        'template', 'sort', 'allowedchildtemplates', 'body', 'slug',
    ];

    /** @var array<string, array{allowed: list<string>, default: list<string>, fields: list<array<string, mixed>>, builder: bool}> */
    private array $resolved = [];

    public function __construct(
        private readonly string $templatesPath,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * Block types a page using $template may use, in the order declared by the
     * manifest. Unknown / non-existent block names are dropped silently.
     *
     * Returns [] when the template declares no allow-list — meaning "all blocks
     * available" (the default, backward-compatible behavior). Also returns []
     * when an allow-list was declared but nothing survived filtering, so the
     * caller falls back to the full palette rather than showing an empty one.
     *
     * @return list<string>
     */
    public function allowedBlocks(string $template): array
    {
        return $this->resolve($template)['allowed'];
    }

    /**
     * Ordered block types to pre-insert into a NEW page of $template.
     *
     * Returns [] when the template declares none — the caller keeps its own
     * default (a single empty text block). Dropped silently: unknown block
     * names, and any block that is not a member of the effective allow-list
     * (pre-seeded blocks must be a subset of the allowed blocks).
     *
     * @return list<string>
     */
    public function defaultBlocks(string $template): array
    {
        return $this->resolve($template)['default'];
    }

    /**
     * Page-level fields declared by the template's manifest, normalized to
     * list form (`name` injected, list `item:` → `item_fields:`).
     *
     * Dropped silently: names that are not valid front-matter keys, names
     * reserved for Page metadata ({@see RESERVED_FIELD_NAMES}), and
     * case-insensitive duplicates (front-matter keys are case-insensitive).
     *
     * @return list<array<string, mixed>>
     */
    public function fields(string $template): array
    {
        return $this->resolve($template)['fields'];
    }

    /**
     * Whether pages of $template use the block page builder. `blocks: false`
     * in the manifest turns it off (a fields-only template); default true.
     */
    public function builderEnabled(string $template): bool
    {
        return $this->resolve($template)['builder'];
    }

    /** @return array{allowed: list<string>, default: list<string>, fields: list<array<string, mixed>>, builder: bool} */
    private function resolve(string $template): array
    {
        if (isset($this->resolved[$template])) {
            return $this->resolved[$template];
        }

        $manifest  = $this->loadManifest($template);
        $available = $this->blocks->available();

        // Allow-list: keep declared order, drop duplicates and unknown names.
        $allowed = [];
        foreach ($this->stringList($manifest['allowedBlocks'] ?? null) as $name) {
            if (in_array($name, $available, true) && !in_array($name, $allowed, true)) {
                $allowed[] = $name;
            }
        }

        // Pre-seeded blocks: keep declared order, drop unknown names, and drop
        // any not permitted by the effective allow-list. When $allowed is empty
        // the palette is unrestricted, so only the "known block" check applies.
        $default = [];
        foreach ($this->stringList($manifest['defaultBlocks'] ?? null) as $name) {
            if (!in_array($name, $available, true)) {
                continue; // unknown block type — ignore gracefully
            }
            if ($allowed !== [] && !in_array($name, $allowed, true)) {
                continue; // not a member of the allow-list — drop
            }
            $default[] = $name;
        }

        $rawFields = $manifest['fields'] ?? null;
        $fields    = [];
        $seen      = [];
        foreach (FieldSchema::normalize(is_array($rawFields) ? $rawFields : []) as $field) {
            $name = (string) $field['name'];
            $key  = strtolower($name);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)
                || in_array($key, self::RESERVED_FIELD_NAMES, true)
                || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fields[]   = $field;
        }

        return $this->resolved[$template] = [
            'allowed' => $allowed,
            'default' => $default,
            'fields'  => $fields,
            'builder' => ($manifest['blocks'] ?? true) !== false,
        ];
    }

    /** @return array<string, mixed> */
    private function loadManifest(string $template): array
    {
        if ($this->templatesPath === '' || $template === '') {
            return [];
        }
        $file = rtrim($this->templatesPath, '/') . '/' . $template . '.blocks.yaml';
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Coerce a manifest value into a clean list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }
        return $out;
    }
}

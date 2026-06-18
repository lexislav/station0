<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use Slim\Views\Twig;
use Station0\Service\BlockRegistry;
use Twig\Loader\ArrayLoader;

/**
 * Builds a throwaway on-disk template tree for the block-related tests:
 *
 *   <root>/                         ← templates path
 *     <name>.twig                   ← bare template files
 *     <name>.blocks.yaml            ← optional per-template manifests
 *     blocks/<type>/schema.yaml     ← block schemas (drive available()/schema())
 *
 * Everything lives under a unique temp directory that is removed on teardown.
 */
final class BlockFixtures
{
    public readonly string $templatesPath;
    public readonly string $blocksPath;

    public function __construct()
    {
        $this->templatesPath = sys_get_temp_dir() . '/s0-tpl-' . bin2hex(random_bytes(6));
        $this->blocksPath    = $this->templatesPath . '/blocks';
        @mkdir($this->blocksPath, 0775, true);
    }

    /** Write site/templates/blocks/<type>/schema.yaml. */
    public function addBlock(string $type, string $schemaYaml): void
    {
        $dir = $this->blocksPath . '/' . $type;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/schema.yaml', $schemaYaml);
    }

    /** Write a bare template file and (optionally) its <name>.blocks.yaml manifest. */
    public function addTemplate(string $name, ?string $manifestYaml = null): void
    {
        file_put_contents($this->templatesPath . '/' . $name . '.twig', '');
        if ($manifestYaml !== null) {
            file_put_contents($this->templatesPath . '/' . $name . '.blocks.yaml', $manifestYaml);
        }
    }

    public function registry(): BlockRegistry
    {
        return new BlockRegistry($this->blocksPath, new Twig(new ArrayLoader()));
    }

    public function cleanup(): void
    {
        $this->rrmdir($this->templatesPath);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

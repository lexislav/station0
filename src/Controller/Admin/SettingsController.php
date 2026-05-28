<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Composer\InstalledVersions;
use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Twig $twig,
        private readonly array $roles,
        private readonly string $projectRoot,
        private readonly string $templatesDir,
        private readonly string $station0Root,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $pageTemplates = [];
        if (is_dir($this->templatesDir)) {
            foreach (glob($this->templatesDir . '/*.twig') ?: [] as $f) {
                $name = basename($f, '.twig');
                if ($name === 'layout') continue; // base layout, not a content template
                $pageTemplates[] = $name;
            }
            sort($pageTemplates);
        }

        $blockTypes = [];
        $blocksDir = $this->templatesDir . '/blocks';
        if (is_dir($blocksDir)) {
            foreach (glob($blocksDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $blockTypes[] = basename($d);
            }
            sort($blockTypes);
        }

        $cmsVersion = class_exists(InstalledVersions::class)
            ? (InstalledVersions::getPrettyVersion('lexislav/station0') ?? 'dev')
            : 'dev';

        $deps = [];
        if (class_exists(InstalledVersions::class)) {
            foreach (['slim/slim', 'twig/twig', 'league/commonmark', 'delight-im/auth'] as $pkg) {
                $v = InstalledVersions::getPrettyVersion($pkg);
                if ($v !== null) {
                    $deps[$pkg] = $v;
                }
            }
        }

        return $this->twig->render($response, '@admin/settings.twig', [
            'user' => [
                'email'   => $this->auth->getEmail(),
                'isAdmin' => $this->auth->hasRole($this->roles['admin']),
            ],
            'phpVersion'    => PHP_VERSION,
            'cmsVersion'    => $cmsVersion,
            'pageTemplates' => $pageTemplates,
            'blockTypes'    => $blockTypes,
            'deps'          => $deps,
        ]);
    }
}

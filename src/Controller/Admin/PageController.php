<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\BlockRegistry;
use Station0\Service\ContentRepository;
use Station0\Service\FileCache;
use Station0\Service\Page;
use Station0\Service\PageRenderer;
use Station0\Support\Slug;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class PageController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly FileCache $cache,
        private readonly BlockRegistry $blocks,
        private readonly PageRenderer $renderer,
        private readonly string $adminPath,
    ) {}

    // ─── List ───

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, '@admin/pages/list.twig', [
            'pages' => $this->content->all(),
            'csrf'  => $this->csrfFields($request),
        ]);
    }

    // ─── Create ───

    public function createForm(Request $request, Response $response): Response
    {
        [$blockTypes, $blockTypesMap] = $this->blockTypeData();

        return $this->twig->render($response, '@admin/pages/edit.twig', [
            'mode'          => 'new',
            'page'          => new Page(slug: '', title: '', body: ''),
            'parents'       => $this->parentOptions(),
            'blocks'        => [['type' => 'text', 'body' => '']],
            'blockTypes'    => $blockTypes,
            'blockTypesMap' => $blockTypesMap,
            'csrf'          => $this->csrfFields($request),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $title     = trim((string) ($data['title'] ?? ''));
        $rawSlug   = trim((string) ($data['slug'] ?? ''));
        $parentUrl = trim((string) ($data['parent'] ?? '/'));

        if ($title === '') {
            return $response->withStatus(422);
        }

        $slug = $rawSlug !== '' ? Slug::sanitize($rawSlug) : Slug::fromTitle($title);
        if ($slug === '') {
            $slug = 'page-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        // Resolve physical save directory
        $childrenDir = $this->content->childrenDirForUrl($parentUrl);
        @mkdir($childrenDir, 0775, true);

        $template = (string) ($data['template'] ?? 'page');
        if ($template === '') {
            $template = 'page';
        }

        // Each page lives in its own directory named after the slug;
        // the content file inside is named after the template.
        $pageDir = rtrim($childrenDir, '/') . '/' . $slug;
        if (is_dir($pageDir)) {
            $slug    .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $pageDir  = rtrim($childrenDir, '/') . '/' . $slug;
        }
        @mkdir($pageDir, 0775, true);
        $filePath = $pageDir . '/' . $template . '.txt';

        $page = new Page(
            slug:      $slug,
            title:     $title,
            body:      (string) ($data['body'] ?? ''),
            metatitle: trim((string) ($data['metatitle'] ?? '')) ?: null,
            published: isset($data['published']),
            template:  $template,
        );

        $this->content->save($page, $filePath);
        $this->cache->flush();

        $newUrl  = rtrim($parentUrl === '/' ? '' : $parentUrl, '/') . '/' . $slug;
        $editKey = ltrim($newUrl, '/');
        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/pages/' . $editKey . '/edit');
    }

    // ─── Edit / Update ───

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $urlPath = $this->argsToUrlPath($args);
        $page    = $this->content->find($urlPath);

        if ($page === null) {
            return $response->withStatus(404);
        }

        [$blockTypes, $blockTypesMap] = $this->blockTypeData();

        return $this->twig->render($response, '@admin/pages/edit.twig', [
            'mode'          => 'edit',
            'page'          => $page,
            'blocks'        => $this->renderer->parseBlocks($page->body),
            'blockTypes'    => $blockTypes,
            'blockTypesMap' => $blockTypesMap,
            'csrf'          => $this->csrfFields($request),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $urlPath = $this->argsToUrlPath($args);
        $page    = $this->content->find($urlPath);

        if ($page === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $page->title     = trim((string) ($data['title'] ?? $page->title));
        $page->body      = (string) ($data['body'] ?? $page->body);
        $page->metatitle = trim((string) ($data['metatitle'] ?? '')) ?: null;
        $page->published = isset($data['published']);
        $page->template  = (string) ($data['template'] ?? $page->template);

        $newSlug = trim((string) ($data['slug'] ?? ''));
        if ($newSlug !== '' && $page->slug !== '' && $newSlug !== $page->slug) {
            $this->content->rename($page, $newSlug);
        }

        $this->content->save($page);
        $this->cache->flush();

        $editKey = $page->urlPath === '/' ? '~' : ltrim($page->urlPath, '/');
        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/pages/' . $editKey . '/edit');
    }

    // ─── Delete ───

    public function delete(Request $request, Response $response, array $args): Response
    {
        $urlPath = $this->argsToUrlPath($args);
        $this->content->delete($urlPath);
        $this->cache->flush();
        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/pages');
    }

    // ─── Helpers ───

    /** Convert Slim route {path:.+} arg to a leading-slash URL path. '~' is the homepage sentinel. */
    private function argsToUrlPath(array $args): string
    {
        $raw = (string) ($args['path'] ?? '');
        if ($raw === '~') {
            return '/';
        }
        return '/' . ltrim($raw, '/');
    }

    /** All page URLs for the "Parent" dropdown, including root "/" */
    private function parentOptions(): array
    {
        $opts = [['url' => '/', 'label' => '/ (root)']];
        foreach ($this->content->all() as $p) {
            if ($p->urlPath !== '/') {
                $depth  = $p->depth();
                $indent = str_repeat('— ', $depth - 1);
                $opts[] = ['url' => $p->urlPath, 'label' => $indent . $p->urlPath . ' (' . $p->title . ')'];
            }
        }
        return $opts;
    }

    /**
     * Returns [list, map] of available block types.
     * list  → ordered array for rendering "+ Add block" buttons
     * map   → keyed by type for O(1) lookup in Twig partials
     *
     * @return array{list<array<string, mixed>>, array<string, array<string, mixed>>}
     */
    private function blockTypeData(): array
    {
        $list = [];
        foreach ($this->blocks->available() as $type) {
            $schema = $this->blocks->schema($type);
            if ($schema === null) {
                continue;
            }
            $list[] = [
                'type'   => $type,
                'label'  => $schema['label'] ?? $type,
                'fields' => $this->normalizeFields($schema['fields'] ?? []),
            ];
        }
        $map = array_combine(array_column($list, 'type'), $list);
        return [$list, $map];
    }

    /** @param array<string, mixed> $raw */
    private function normalizeFields(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $def) {
            $field         = is_array($def) ? $def : [];
            $field['name'] = (string) $name;
            if (isset($field['item']) && is_array($field['item'])) {
                $field['item_fields'] = $this->normalizeFields($field['item']);
                unset($field['item']);
            }
            $out[] = $field;
        }
        return $out;
    }

    private function csrfFields(Request $request): array
    {
        return [
            'nameKey'  => $this->csrf->getTokenNameKey(),
            'valueKey' => $this->csrf->getTokenValueKey(),
            'name'     => $request->getAttribute($this->csrf->getTokenNameKey()),
            'value'    => $request->getAttribute($this->csrf->getTokenValueKey()),
        ];
    }
}

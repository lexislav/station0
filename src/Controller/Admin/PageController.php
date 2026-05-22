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
        private readonly string $templatesPath = '',
    ) {}

    // ─── List ───

    public function index(Request $request, Response $response): Response
    {
        $pages = $this->content->all();
        $tree  = $this->buildTree($pages);

        // Flat list of stream nodes (pages with AllowedChildTemplates) in tree
        // order, used by the Streams tab in the list view.
        $streams = [];
        $this->collectStreamNodes($tree, $streams);

        return $this->twig->render($response, '@admin/pages/list.twig', [
            'pages'   => $pages,
            'tree'    => $tree,
            'streams' => $streams,
            'csrf'    => $this->csrfFields($request),
        ]);
    }

    // ─── Reorder (drag & drop) ───

    public function reorder(Request $request, Response $response): Response
    {
        $raw  = (string) $request->getBody();
        $data = json_decode($raw, true) ?: (array) $request->getParsedBody();

        $parentUrl = (string) ($data['parent'] ?? '/');
        $order     = (array) ($data['order'] ?? []);
        $moved     = isset($data['moved']) && is_array($data['moved']) ? $data['moved'] : null;

        try {
            if ($moved !== null && !empty($moved['from'])) {
                $movedPage = $this->content->find((string) $moved['from']);
                if ($movedPage === null) {
                    return $this->jsonResponse($response, ['ok' => false, 'error' => 'Source page not found.'], 404);
                }
                $this->content->move($movedPage, $parentUrl);
            }

            $childrenDir = rtrim($this->content->childrenDirForUrl($parentUrl), '/');
            $step = 10;
            foreach ($order as $idx => $slug) {
                $slug = (string) $slug;
                if ($slug === '') {
                    continue;
                }
                $childUrl = ($parentUrl === '/' ? '' : rtrim($parentUrl, '/')) . '/' . $slug;
                $page = $this->content->find($childUrl);
                if ($page === null) {
                    continue;
                }
                $page->sort = ($idx + 1) * $step;
                $this->content->save($page);
            }

            $this->cache->flush();
            return $this->jsonResponse($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Build a nested tree from the flat (already hierarchically sorted) list.
     * Each node: {page, children, isStream, draftCount}
     *
     * @param  Page[] $pages
     * @return list<array{page: Page, children: array, isStream: bool, draftCount: int}>
     */
    private function buildTree(array $pages): array
    {
        $byUrl = [];
        foreach ($pages as $p) {
            $byUrl[$p->urlPath] = [
                'page'       => $p,
                'children'   => [],
                'isStream'   => !empty($p->allowedChildTemplates),
                'draftCount' => 0,
            ];
        }
        $roots = [];
        foreach ($byUrl as $url => &$node) {
            if ($url === '/') {
                $roots[] = &$node;
                continue;
            }
            $parentUrl = rtrim(dirname($url), '/');
            if ($parentUrl === '') {
                $parentUrl = '/';
            }
            if (isset($byUrl[$parentUrl])) {
                $byUrl[$parentUrl]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        // Annotate each node with the draft count among its direct children.
        foreach ($byUrl as &$node) {
            $node['draftCount'] = count(array_filter(
                $node['children'],
                static fn (array $c): bool => !$c['page']->published,
            ));
        }
        return $roots;
    }

    /**
     * Walk the tree depth-first and collect all stream nodes into $out.
     *
     * @param list<array> $nodes
     * @param list<array> $out
     */
    private function collectStreamNodes(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if ($node['isStream']) {
                $out[] = $node;
            }
            $this->collectStreamNodes($node['children'], $out);
        }
    }

    // ─── Templates API (used by the parent-change JS) ───

    public function templatesForParent(Request $request, Response $response): Response
    {
        $parentUrl  = (string) ($request->getQueryParams()['parent'] ?? '/');
        $parentPage = $this->content->find($parentUrl);
        return $this->jsonResponse($response, ['templates' => $this->availablePageTemplates($parentPage)]);
    }

    // ─── Create ───

    public function createForm(Request $request, Response $response): Response
    {
        [$blockTypes, $blockTypesMap] = $this->blockTypeData();

        $preselectedParent = (string) ($request->getQueryParams()['parent'] ?? '/');
        $parentPage        = $this->content->find($preselectedParent);
        if ($preselectedParent !== '/' && $parentPage === null) {
            $preselectedParent = '/';
        }

        return $this->twig->render($response, '@admin/pages/edit.twig', [
            'mode'               => 'new',
            'page'               => new Page(slug: '', title: '', body: ''),
            'parents'            => $this->parentOptions(),
            'selectedParent'     => $preselectedParent,
            'blocks'             => [['type' => 'text', 'body' => '']],
            'blockTypes'         => $blockTypes,
            'blockTypesMap'      => $blockTypesMap,
            'availableTemplates' => $this->availablePageTemplates($parentPage),
            'csrf'               => $this->csrfFields($request),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $title     = trim((string) ($data['title'] ?? ''));
        $rawSlug   = trim((string) ($data['slug'] ?? ''));
        $parentUrl = trim((string) ($data['parent'] ?? '/'));

        $template = (string) ($data['template'] ?? 'page');
        if ($template === '') {
            $template = 'page';
        }
        $allowedChildTemplates = $this->parseTemplateList((string) ($data['allowed_child_templates'] ?? ''));

        if ($title === '') {
            return $response->withStatus(422);
        }

        $parentPage = $this->content->find($parentUrl);

        try {
            $this->content->assertChildTemplateAllowed($parentUrl, $template);
        } catch (\RuntimeException $e) {
            [$blockTypes, $blockTypesMap] = $this->blockTypeData();
            $draft = new Page(
                slug: $rawSlug,
                title: $title,
                body: (string) ($data['body'] ?? ''),
                metatitle: trim((string) ($data['metatitle'] ?? '')) ?: null,
                published: isset($data['published']),
                template: $template,
                publishedAt: $this->normalizePublishedAt(trim((string) ($data['published_at'] ?? ''))),
                allowedChildTemplates: $allowedChildTemplates,
            );
            return $this->twig->render($response->withStatus(422), '@admin/pages/edit.twig', [
                'mode'               => 'new',
                'page'               => $draft,
                'parents'            => $this->parentOptions(),
                'selectedParent'     => $parentUrl,
                'blocks'             => $this->renderer->parseBlocks($draft->body),
                'blockTypes'         => $blockTypes,
                'blockTypesMap'      => $blockTypesMap,
                'availableTemplates' => $this->availablePageTemplates($parentPage),
                'error'              => $e->getMessage(),
                'csrf'               => $this->csrfFields($request),
            ]);
        }

        $slug = $rawSlug !== '' ? Slug::sanitize($rawSlug) : Slug::fromTitle($title);
        if ($slug === '') {
            $slug = 'page-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        // Resolve physical save directory
        $childrenDir = $this->content->childrenDirForUrl($parentUrl);
        @mkdir($childrenDir, 0775, true);

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
            slug:        $slug,
            title:       $title,
            body:        (string) ($data['body'] ?? ''),
            metatitle:   trim((string) ($data['metatitle'] ?? '')) ?: null,
            published:   isset($data['published']),
            template:    $template,
            publishedAt: $this->normalizePublishedAt(trim((string) ($data['published_at'] ?? ''))),
            allowedChildTemplates: $allowedChildTemplates,
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
        $parentPage = $this->parentPageOf($page->urlPath);

        return $this->twig->render($response, '@admin/pages/edit.twig', [
            'mode'               => 'edit',
            'page'               => $page,
            'breadcrumb'         => $this->breadcrumbFor($page->urlPath),
            'blocks'             => $this->renderer->parseBlocks($page->body),
            'blockTypes'         => $blockTypes,
            'blockTypesMap'      => $blockTypesMap,
            'availableTemplates' => $this->availablePageTemplates($parentPage),
            'csrf'               => $this->csrfFields($request),
        ]);
    }

    /**
     * Convert an HTML datetime-local value ("2026-05-12T14:30") to the
     * stored format ("2026-05-12 14:30"). Empty input → null.
     */
    private function normalizePublishedAt(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : date('Y-m-d H:i', $ts);
    }

    /**
     * Ordered ancestor + self titles for the breadcrumb.
     * @return list<array{title: string, urlPath: string}>
     */
    private function breadcrumbFor(string $urlPath): array
    {
        $crumbs = [];
        $segments = array_values(array_filter(explode('/', trim($urlPath, '/')), 'strlen'));
        $accum = '';
        foreach ($segments as $seg) {
            $accum  .= '/' . $seg;
            $ancestor = $this->content->find($accum);
            $crumbs[] = [
                'title'   => $ancestor?->title ?: $seg,
                'urlPath' => $accum,
            ];
        }
        return $crumbs;
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
        if (array_key_exists('allowed_child_templates', $data)) {
            $page->allowedChildTemplates = $this->parseTemplateList((string) $data['allowed_child_templates']);
        }

        $rawPublishedAt = trim((string) ($data['published_at'] ?? ''));
        $page->publishedAt = $this->normalizePublishedAt($rawPublishedAt);

        if ($page->urlPath !== '/') {
            $parentUrl  = rtrim(dirname($page->urlPath), '/') ?: '/';
            $parentPage = $this->content->find($parentUrl);
            try {
                $this->content->assertChildTemplateAllowed($parentUrl, $page->template);
            } catch (\RuntimeException $e) {
                [$blockTypes, $blockTypesMap] = $this->blockTypeData();
                return $this->twig->render($response->withStatus(422), '@admin/pages/edit.twig', [
                    'mode'               => 'edit',
                    'page'               => $page,
                    'breadcrumb'         => $this->breadcrumbFor($page->urlPath),
                    'blocks'             => $this->renderer->parseBlocks($page->body),
                    'blockTypes'         => $blockTypes,
                    'blockTypesMap'      => $blockTypesMap,
                    'availableTemplates' => $this->availablePageTemplates($parentPage),
                    'error'              => $e->getMessage(),
                    'csrf'               => $this->csrfFields($request),
                ]);
            }
        }

        $newSlug = trim((string) ($data['slug'] ?? ''));
        if ($newSlug === '' && $page->title !== '') {
            $newSlug = Slug::fromTitle($page->title);
        }
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

    /** Look up the parent Page for a given URL path, or null for root / root children. */
    private function parentPageOf(string $urlPath): ?Page
    {
        if ($urlPath === '/') {
            return null;
        }
        $parentUrl = rtrim(dirname($urlPath), '/') ?: '/';
        return $this->content->find($parentUrl);
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

    /** @return list<string> */
    private function availablePageTemplates(?Page $parent = null): array
    {
        if ($this->templatesPath === '') {
            return [];
        }
        $files = glob(rtrim($this->templatesPath, '/') . '/*.twig') ?: [];
        $names = [];
        foreach ($files as $file) {
            $name = basename($file, '.twig');
            if ($name !== 'layout') {
                $names[] = $name;
            }
        }
        sort($names);

        if ($parent !== null) {
            $allowed = $this->content->effectiveAllowedChildTemplates($parent);
            if (!empty($allowed)) {
                // Parent restricts: show only what it permits.
                return array_values(array_filter($names, fn ($n) => in_array($n, $allowed, true)));
            }
        }

        // No restriction from parent: hide templates that are dedicated child
        // templates (listed in some page's AllowedChildTemplates but never used
        // as an own template themselves).
        $childOnly = $this->content->childOnlyTemplates();
        if (!empty($childOnly)) {
            $names = array_values(array_filter($names, fn ($n) => !in_array($n, $childOnly, true)));
        }

        return $names;
    }

    /** @return list<string> */
    private function parseTemplateList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
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

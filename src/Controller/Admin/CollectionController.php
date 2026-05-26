<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\CollectionItem;
use Station0\Service\CollectionRepository;
use Station0\Service\FileCache;
use Station0\Service\MediaService;
use Station0\Support\Slug;

final class CollectionController
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly FileCache $cache,
        private readonly MediaService $media,
        private readonly string $adminPath,
    ) {}

    // ─── Collection list ───

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, '@admin/collections/list.twig', [
            'collections' => $this->collections->collections(),
            'csrf'        => $this->csrfFields($request),
        ]);
    }

    // ─── Item list ───

    public function items(Request $request, Response $response, array $args): Response
    {
        $name   = (string) ($args['name'] ?? '');
        $schema = $this->collections->schema($name);
        $items  = $this->collections->items($name, true);

        return $this->twig->render($response, '@admin/collections/items.twig', [
            'collectionName'  => $name,
            'collectionLabel' => $schema['label'] ?? $this->labelFromName($name),
            'schema'          => $schema,
            'items'           => $items,
            'csrf'            => $this->csrfFields($request),
        ]);
    }

    // ─── Create form ───

    public function createForm(Request $request, Response $response, array $args): Response
    {
        $name   = (string) ($args['name'] ?? '');
        $schema = $this->collections->schema($name);

        return $this->twig->render($response, '@admin/collections/form.twig', [
            'collectionName'  => $name,
            'collectionLabel' => $schema['label'] ?? $this->labelFromName($name),
            'schema'          => $schema,
            'fields'          => $this->normalizeFields($schema['fields'] ?? []),
            'item'            => null,
            'csrf'            => $this->csrfFields($request),
        ]);
    }

    // ─── Store (create) ───

    public function store(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $data = (array) $request->getParsedBody();

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->redirectToItems($response, $name);
        }

        $slug = Slug::sanitize((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = Slug::sanitize($title);
        }
        // Ensure uniqueness by appending -2, -3, …
        $base = $slug;
        $i    = 2;
        while ($this->collections->find($name, $slug) !== null) {
            $slug = $base . '-' . $i++;
        }

        $schema = $this->collections->schema($name);
        $extra  = $this->extractExtraFields($data, $schema);

        $item = new CollectionItem(
            collection: $name,
            slug:       $slug,
            title:      $title,
            body:       trim((string) ($data['body'] ?? '')),
            published:  isset($data['published']),
            sort:       ($data['sort'] ?? '') !== '' ? (int) $data['sort'] : null,
            extra:      $extra,
        );

        $filePath = rtrim($this->collections->itemDir($name, $slug), '/') . '/item.txt';
        $this->collections->save($item, $filePath);
        $this->cache->flush();

        return $this->redirectToItems($response, $name);
    }

    // ─── Edit form ───

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $slug = (string) ($args['slug'] ?? '');

        $item = $this->collections->find($name, $slug);
        if ($item === null) {
            return $response->withStatus(404);
        }

        $schema = $this->collections->schema($name);

        return $this->twig->render($response, '@admin/collections/form.twig', [
            'collectionName'  => $name,
            'collectionLabel' => $schema['label'] ?? $this->labelFromName($name),
            'schema'          => $schema,
            'fields'          => $this->normalizeFields($schema['fields'] ?? []),
            'item'            => $item,
            'csrf'            => $this->csrfFields($request),
        ]);
    }

    // ─── Update ───

    public function update(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $slug = (string) ($args['slug'] ?? '');

        $item = $this->collections->find($name, $slug);
        if ($item === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();

        $item->title     = trim((string) ($data['title'] ?? $item->title));
        $item->body      = trim((string) ($data['body'] ?? ''));
        $item->published = isset($data['published']);
        $item->sort      = ($data['sort'] ?? '') !== '' ? (int) $data['sort'] : null;

        $schema     = $this->collections->schema($name);
        $item->extra = $this->extractExtraFields($data, $schema);

        // Handle optional slug rename.
        $newSlug = Slug::sanitize((string) ($data['slug'] ?? ''));
        if ($newSlug !== '' && $newSlug !== $slug) {
            try {
                $this->collections->rename($item, $newSlug);
            } catch (\RuntimeException) {
                // Ignore rename failure — keep existing slug.
            }
        }

        $this->collections->save($item);
        $this->cache->flush();

        return $this->redirectToItems($response, $name);
    }

    // ─── Delete ───

    public function delete(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');
        $slug = (string) ($args['slug'] ?? '');

        $this->collections->delete($name, $slug);
        $this->cache->flush();

        return $this->redirectToItems($response, $name);
    }

    // ─── File upload for collection items ───

    public function upload(Request $request, Response $response): Response
    {
        $data           = (array) $request->getParsedBody();
        $collectionName = trim((string) ($data['collectionName'] ?? ''));
        $itemSlug       = trim((string) ($data['itemSlug'] ?? ''));

        if ($collectionName === '' || $itemSlug === '') {
            $response->getBody()->write(json_encode(['error' => 'collectionName and itemSlug are required']));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $files = $request->getUploadedFiles();
        $file  = $files['file'] ?? null;

        if ($file === null) {
            $response->getBody()->write(json_encode(['error' => 'No file uploaded']));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->media->storeForCollection($collectionName, $itemSlug, $file);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\RuntimeException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }
    }

    // ─── Helpers ───

    /** Extract schema-defined extra fields from POST data. */
    private function extractExtraFields(array $data, array $schema): array
    {
        $extra  = [];
        $fields = $this->normalizeFields($schema['fields'] ?? []);

        if (empty($fields)) {
            // Free-form: no schema — nothing extra to extract (body-only mode).
            return [];
        }

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type  = (string) ($field['type'] ?? 'text');
            $value = $data[$name] ?? null;

            if ($type === 'boolean') {
                $extra[$name] = isset($data[$name]) ? 'true' : 'false';
            } elseif ($value !== null) {
                $extra[$name] = trim((string) $value);
            }
        }

        return $extra;
    }

    /**
     * Convert dict-form schema fields to list form (mirrors PageController).
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
            $out[]         = $field;
        }
        return $out;
    }

    private function labelFromName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
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

    private function redirectToItems(Response $response, string $name): Response
    {
        return $response
            ->withHeader('Location', $this->adminPath . '/collections/' . $name)
            ->withStatus(302);
    }
}

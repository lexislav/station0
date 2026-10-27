<?php

declare(strict_types=1);

namespace Station0\Plugin\Redirects;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Station0\Service\ContentRepository;

/**
 * Admin: rule list + form + "Test a URL" ({adminPath}/redirects), the 404
 * log (/redirects/404) and the JSON endpoints the page panel uses.
 *
 * Editors manage exact, non-force rules; patterns, regexes and force rules
 * redirect broadly or hide pages, so they need an admin.
 */
final class AdminController
{
    /**
     * @param \Closure(): Log   $log
     * @param \Closure(): array $user  the signed-in admin user (id, email, roles, isAdmin)
     */
    public function __construct(
        private readonly Store $store,
        private readonly \Closure $log,
        private readonly Twig $twig,
        private readonly ContentRepository $pages,
        private readonly \Closure $user,
        private readonly string $adminPath,
    ) {}

    public function index(Request $request, Response $response, array $args = [], array $form = [], ?string $error = null): Response
    {
        $query  = $request->getQueryParams();
        $loaded = $this->store->load();
        $hits   = $this->hits($loaded['rules']);

        $rows = [];
        foreach ($loaded['rules'] as $rule) {
            $rows[] = [
                'rule'     => $rule,
                'warnings' => $this->warnings($rule),
                'hits'     => $hits[$rule['id']] ?? null,
                'editable' => $this->mayEdit($rule),
            ];
        }

        $editing = isset($query['edit']) ? $this->store->find((string) $query['edit']) : null;
        $form = $form ?: ($editing !== null && $this->mayEdit($editing) ? $editing : [
            'from' => (string) ($query['from'] ?? ''), 'to' => (string) ($query['to'] ?? ''), 'status' => 301,
        ]);
        $form['back'] ??= isset($query['back']) ? self::logQuery((string) $query['back']) : null;

        $test = null;
        if (isset($query['test']) && trim((string) $query['test']) !== '') {
            [$path, $q] = array_pad(explode('?', trim((string) $query['test']), 2), 2, '');
            $hit = $this->store->matcher()->test($path, $q);
            $page = $hit === null ? $this->pages->find(Rules::normalizePath($path)) : null;
            $test = ['input' => (string) $query['test'], 'hit' => $hit, 'page' => $page !== null && $page->isLive()];
        }

        return $this->twig->render($response->withStatus($error !== null ? 422 : 200), '@redirects/list.twig', [
            'rows'      => $rows,
            'invalid'   => $loaded['invalid'],
            'broken'    => $loaded['broken'],
            'form'      => $form,
            'error'     => $error,
            'test'      => $test,
            'isAdmin'   => $this->isAdmin(),
            'saved'     => isset($query['saved']),
            'file'      => $this->store->file(),
            'activeNav' => 'plugin-redirects',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $replaceId = trim((string) ($data['id'] ?? '')) ?: null;
        $raw = [
            'from'     => (string) ($data['from'] ?? ''),
            'to'       => (string) ($data['to'] ?? ''),
            'type'     => (string) ($data['type'] ?? ''),
            'status'   => (int) ($data['status'] ?? 301),
            'force'    => !empty($data['force']),
            'caseless' => !empty($data['caseless']),
            'count'    => !empty($data['count']),
            'query'    => ($data['query'] ?? '') === 'drop' ? 'drop' : 'keep',
            'note'     => (string) ($data['note'] ?? ''),
        ];
        if ($replaceId !== null && ($existing = $this->store->find($replaceId)) !== null) {
            $raw['auto'] = $existing['auto'];
        }

        try {
            $rule = Rules::normalize($raw);
            $old  = $replaceId !== null ? $this->store->find($replaceId) : null;
            if (!$this->mayEdit($rule) || ($old !== null && !$this->mayEdit($old))) {
                throw new \InvalidArgumentException('Only an admin can manage pattern, regex or force rules.');
            }
            $rule = $this->store->save($raw, $replaceId);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if ($this->wantsJson($request)) {
                return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 422);
            }
            $back = isset($data['back']) ? self::logQuery((string) $data['back']) : null;
            return $this->index($request, $response, [], ['id' => $replaceId, 'back' => $back] + $raw, $e->getMessage());
        }

        if ($this->wantsJson($request)) {
            return $this->json($response, ['ok' => true, 'rule' => $rule]);
        }
        if (isset($data['back'])) {
            return $this->redirect($response, self::logUrl(self::logQuery((string) $data['back'])));
        }
        return $this->redirect($response, '/redirects?saved=1');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $rule = $this->store->find((string) ($args['id'] ?? ''));
        if ($rule === null || !$this->mayEdit($rule)) {
            return $this->wantsJson($request)
                ? $this->json($response, ['ok' => false, 'error' => $rule === null ? 'Not found.' : 'Forbidden.'], $rule === null ? 404 : 403)
                : $response->withStatus($rule === null ? 404 : 403);
        }
        try {
            $this->store->remove($rule['id']);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return $this->wantsJson($request)
            ? $this->json($response, ['ok' => true])
            : $this->redirect($response, '/redirects?saved=1');
    }

    public const LOG_PER_PAGE = 100;

    /**
     * 404 log: drops the misses a rule or a live page now answers, then lists
     * a filtered, sorted page with a suggested target per path.
     */
    public function log(Request $request, Response $response): Response
    {
        $query  = $request->getQueryParams();
        $filter = self::logFilter($query);
        $page   = max(1, (int) ($query['page'] ?? 1));
        $log    = ($this->log)();

        $live    = array_map(fn ($p) => $p->urlPath, $this->pages->all(false));
        $isLive  = array_flip($live);
        $matcher = $this->store->matcher();
        $resolved = $log->forgetResolved(fn (string $path): bool => isset($isLive[$path]) || $matcher->test($path) !== null);

        $result    = $log->search($filter, self::LOG_PER_PAGE, ($page - 1) * self::LOG_PER_PAGE);
        $suggester = new Suggester($live);
        foreach ($result['entries'] as &$entry) {
            $entry['suggest'] = $filter['ignored'] ? null : $suggester->suggest($entry['path']);
        }
        unset($entry);

        return $this->twig->render($response, '@redirects/log.twig', [
            'entries'     => $result['entries'],
            'total'       => $result['total'],
            'page'        => $page,
            'pages'       => max(1, (int) ceil($result['total'] / self::LOG_PER_PAGE)),
            'filter'      => $filter,
            'params'      => array_filter($filter, fn ($v) => $v !== '' && $v !== false),
            'resolved'    => $resolved,
            'showIgnored' => $filter['ignored'],
            'isAdmin'     => $this->isAdmin(),
            'activeNav'   => 'plugin-redirects',
        ]);
    }

    /** Ignore (or with `undo` show again) one `path` or the checked `paths[]`. */
    public function ignore(Request $request, Response $response): Response
    {
        $data  = (array) $request->getParsedBody();
        $paths = array_map('strval', array_filter((array) ($data['paths'] ?? [$data['path'] ?? '']), 'is_scalar'));
        ($this->log)()->ignore(array_values(array_filter($paths, fn ($p) => $p !== '')), empty($data['undo']));
        return $this->redirect($response, self::logUrl(self::logQuery((string) ($data['back'] ?? ''))));
    }

    public function clear(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withStatus(403);
        }
        ($this->log)()->clear();
        return $this->redirect($response, '/redirects/404');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{q: string, ref: string, min: string, days: string, sort: string, dir: string, ignored: bool} */
    private static function logFilter(array $query): array
    {
        $sort = (string) ($query['sort'] ?? '');
        return [
            'q'       => trim((string) ($query['q'] ?? '')),
            'ref'     => trim((string) ($query['ref'] ?? '')),
            'min'     => (int) ($query['min'] ?? 0) > 1 ? (string) (int) $query['min'] : '',
            'days'    => (int) ($query['days'] ?? 0) > 0 ? (string) (int) $query['days'] : '',
            'sort'    => isset(Log::SORTS[$sort]) && $sort !== 'hits' ? $sort : '',
            'dir'     => in_array($query['dir'] ?? '', ['asc', 'desc'], true) ? (string) $query['dir'] : '',
            'ignored' => isset($query['ignored']) && $query['ignored'] !== '0',
        ];
    }

    /** A posted / linked log query string, reduced to the known filters. */
    private static function logQuery(string $back): string
    {
        parse_str(ltrim($back, '?'), $query);
        $filter = self::logFilter($query);
        $filter['ignored'] = $filter['ignored'] ? '1' : '';
        return http_build_query(array_filter($filter, fn ($v) => $v !== ''));
    }

    private static function logUrl(string $query): string
    {
        return '/redirects/404' . ($query !== '' ? '?' . $query : '');
    }

    /** @return list<string> translation keys */
    private function warnings(array $rule): array
    {
        $warnings = [];
        if ($rule['type'] === 'exact' && !$rule['force']) {
            $page = $this->pages->find($rule['from']);
            if ($page !== null && $page->isLive()) {
                $warnings[] = 'redirects_warn_shadowed';
            }
        }
        $target = Rules::staticTarget($rule);
        if ($target !== null && !str_starts_with($target, '/media/')) {
            $page = $this->pages->find($target);
            if ($page === null || !$page->isLive()) {
                $warnings[] = 'redirects_warn_target';
            }
        }
        return $warnings;
    }

    private function hits(array $rules): array
    {
        if (!in_array(true, array_column($rules, 'count'), true)) {
            return [];
        }
        try {
            return ($this->log)()->hits();
        } catch (\Throwable) {
            return [];
        }
    }

    private function mayEdit(array $rule): bool
    {
        return $this->isAdmin() || ($rule['type'] === 'exact' && !$rule['force']);
    }

    private function isAdmin(): bool
    {
        return (bool) (($this->user)()['isAdmin'] ?? false);
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withStatus(303)->withHeader('Location', $this->adminPath . $path);
    }
}

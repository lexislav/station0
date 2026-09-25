<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\TaskLauncher;
use Station0\Service\TaskRegistry;
use Station0\Service\TaskRuns;

/**
 * Admin UI for site tasks (site/tasks/*.php): list, parameter form, run
 * history. A run is started through TaskLauncher (in the background when
 * possible); the run page polls runStatus() for live output.
 */
final class TaskController
{
    private const HISTORY = 10;

    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly TaskLauncher $launcher,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly Auth $auth,
        private readonly string $adminPath,
        private readonly string $tmpDir,
        private readonly array $lang,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $tasks = array_map(
            fn (array $t) => $t + ['lastRun' => $this->tasks->lastRun($t['name'])],
            $this->tasks->visible(),
        );

        return $this->twig->render($response, '@admin/tasks/list.twig', [
            'tasks'     => $tasks,
            'activeNav' => 'tasks',
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $task = $this->allowed((string) $args['name']);
        if (!is_array($task)) {
            return $response->withStatus($task);
        }

        $runId = (string) ($request->getQueryParams()['run'] ?? '');
        $run   = null;
        if ($runId !== '') {
            if (TaskRuns::taskOf($runId) !== $task['name'] || ($run = $this->tasks->info($runId)) === null) {
                return $response->withStatus(404);
            }
        }

        return $this->render($request, $response, $task, [], [], $run);
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        $task = $this->allowed((string) $args['name']);
        if (!is_array($task)) {
            return $response->withStatus($task);
        }

        $input = (array) $request->getParsedBody();
        $temp  = [];
        foreach ($task['params'] as $param) {
            if ($param['type'] === 'boolean') {
                // Unchecked checkboxes are not posted.
                $input[$param['name']] = isset($input[$param['name']]) ? '1' : '0';
            } elseif ($param['type'] === 'file') {
                $input[$param['name']] = $temp[] = $this->storeUpload(
                    $request->getUploadedFiles()[$param['name']] ?? null,
                );
            }
        }

        try {
            ['values' => $values, 'errors' => $errors] = $this->tasks->resolveParams($task, $input);
            if ($errors === [] && $this->tasks->isRunning($task['name'])) {
                $errors['_task'] = 'running';
            }
            // The launcher moves uploads into the run's own directory.
            $runId = $errors === []
                ? $this->launcher->start($task, $values, 'admin', $this->auth->getEmail())
                : null;
        } finally {
            foreach (array_filter($temp) as $file) {
                @unlink($file);
            }
        }

        if ($runId !== null) {
            return $response->withStatus(303)->withHeader(
                'Location',
                $this->adminPath . '/tasks/' . $task['name'] . '?run=' . rawurlencode($runId),
            );
        }

        // Keep typed values in the form, except uploads (never re-posted).
        foreach ($task['params'] as $param) {
            if ($param['type'] === 'file') {
                unset($input[$param['name']]);
            }
        }
        return $this->render($request, $response, $task, $input, $errors, null)->withStatus(422);
    }

    /** JSON for the run page's live view: status + output lines from `offset`. */
    public function runStatus(Request $request, Response $response, array $args): Response
    {
        $task = $this->allowed((string) $args['name']);
        if (!is_array($task)) {
            return $response->withStatus($task);
        }
        $id  = (string) $args['id'];
        $run = TaskRuns::taskOf($id) === $task['name']
            ? $this->tasks->info($id, max(0, (int) ($request->getQueryParams()['offset'] ?? 0)))
            : null;
        if ($run === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write((string) json_encode([
            'status'   => $run['status'],
            'finished' => $run['finished'],
            'ok'       => $run['ok'],
            'exit'     => $run['exit'],
            'duration' => $run['duration'],
            'lines'    => $run['output'],
            'offset'   => $run['offset'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    // ─── Helpers ───

    /** The task, or an HTTP status (404 unknown, 403 not allowed). */
    private function allowed(string $name): array|int
    {
        $task = $this->tasks->find($name);
        if ($task === null) {
            return 404;
        }
        return $this->tasks->canRun($task) ? $task : 403;
    }

    private function render(Request $request, Response $response, array $task, array $values, array $errors, ?array $run): Response
    {
        $history = $this->tasks->runs()->forTask($task['name'], self::HISTORY);

        return $this->twig->render($response, '@admin/tasks/show.twig', [
            'task'      => $task,
            'params'    => $this->tasks->formParams($task),
            'values'    => $values,
            'errors'    => array_map(fn (string $code) => $this->lang['task_err_' . $code] ?? $code, $errors),
            // The requested run, else the newest one.
            'run'       => $run ?? (isset($history[0]) ? $this->tasks->info($history[0]['id']) : null),
            'history'   => $history,
            'running'   => $this->tasks->isRunning($task['name']),
            'activeNav' => 'tasks',
            'csrf'      => [
                'nameKey'  => $this->csrf->getTokenNameKey(),
                'valueKey' => $this->csrf->getTokenValueKey(),
                'name'     => $request->getAttribute($this->csrf->getTokenNameKey()),
                'value'    => $request->getAttribute($this->csrf->getTokenValueKey()),
            ],
        ]);
    }

    /** Move an upload to a temp file (original extension kept); null when none. */
    private function storeUpload(mixed $file): ?string
    {
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0775, true);
        }
        $ext  = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION)) ?? '');
        $path = $this->tmpDir . '/upload-' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
        $file->moveTo($path);
        return $path;
    }
}

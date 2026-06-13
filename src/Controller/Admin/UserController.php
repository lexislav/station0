<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\UserRepository;

final class UserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly array $rolesMap,
        private readonly string $adminPath,
        private readonly ?Auth $auth = null,
        private readonly array $lang = [],
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->renderList($request, $response);
    }

    private function renderList(Request $request, Response $response, ?string $error = null): Response
    {
        return $this->twig->render($response, '@admin/users/list.twig', [
            'users' => $this->users->all(),
            'csrf' => $this->csrfFields($request),
            'roles' => array_keys($this->rolesMap),
            'error' => $error,
        ]);
    }

    private function t(string $key): string
    {
        return $this->lang[$key] ?? $key;
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, '@admin/users/new.twig', [
            'csrf' => $this->csrfFields($request),
            'roles' => array_keys($this->rolesMap),
            'error' => null,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = (string) ($data['role'] ?? 'editor');

        if ($username === '') {
            return $this->twig->render($response->withStatus(422), '@admin/users/new.twig', [
                'csrf' => $this->csrfFields($request),
                'roles' => array_keys($this->rolesMap),
                'error' => $this->t('err_username_empty'),
            ]);
        }

        try {
            $this->users->create($email, $password, $role, $username);
        } catch (\Throwable $e) {
            return $this->twig->render($response->withStatus(422), '@admin/users/new.twig', [
                'csrf' => $this->csrfFields($request),
                'roles' => array_keys($this->rolesMap),
                'error' => $e->getMessage(),
            ]);
        }

        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/users');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id      = (int) $args['id'];
        $allUsers = $this->users->all();

        // Block deleting your own account.
        if ($this->auth !== null && $id === (int) $this->auth->getUserId()) {
            return $this->renderList($request, $response->withStatus(422), $this->t('err_delete_self'));
        }

        // Block deleting the last remaining admin (would lock everyone out).
        $target = null;
        foreach ($allUsers as $u) {
            if ($u['id'] === $id) {
                $target = $u;
                break;
            }
        }
        if ($target !== null && in_array('admin', $target['roles'], true)) {
            $adminCount = count(array_filter(
                $allUsers,
                static fn (array $u): bool => in_array('admin', $u['roles'], true),
            ));
            if ($adminCount <= 1) {
                return $this->renderList($request, $response->withStatus(422), $this->t('err_delete_last_admin'));
            }
        }

        $this->users->delete($id);
        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/users');
    }

    private function csrfFields(Request $request): array
    {
        return [
            'nameKey' => $this->csrf->getTokenNameKey(),
            'valueKey' => $this->csrf->getTokenValueKey(),
            'name' => $request->getAttribute($this->csrf->getTokenNameKey()),
            'value' => $request->getAttribute($this->csrf->getTokenValueKey()),
        ];
    }
}

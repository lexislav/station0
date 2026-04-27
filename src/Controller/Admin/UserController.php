<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

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
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, '@admin/users/list.twig', [
            'users' => $this->users->all(),
            'csrf' => $this->csrfFields($request),
            'roles' => array_keys($this->rolesMap),
        ]);
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
                'error' => 'Uživatelské jméno nesmí být prázdné.',
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
        $this->users->delete((int) $args['id']);
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

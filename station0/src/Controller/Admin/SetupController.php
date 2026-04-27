<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Delight\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\UserRepository;

final class SetupController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly string $adminPath,
    ) {}

    public function showSetup(Request $request, Response $response): Response
    {
        if ($this->users->hasAny()) {
            return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/login');
        }
        return $this->twig->render($response, '@admin/setup.twig', [
            'error' => null,
            'csrf'  => $this->csrfFields($request),
        ]);
    }

    public function setup(Request $request, Response $response): Response
    {
        if ($this->users->hasAny()) {
            return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/login');
        }

        $data     = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $error    = null;

        if ($username === '' || $email === '' || $password === '') {
            $error = 'Vyplňte všechna pole.';
        } elseif (strlen($password) < 8) {
            $error = 'Heslo musí mít alespoň 8 znaků.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadejte platnou e-mailovou adresu.';
        }

        if ($error !== null) {
            return $this->twig->render($response->withStatus(422), '@admin/setup.twig', [
                'error'    => $error,
                'csrf'     => $this->csrfFields($request),
                'username' => $username,
                'email'    => $email,
            ]);
        }

        try {
            $this->users->create($email, $password, 'admin', $username);
            $this->auth->loginWithUsername($username, $password);
            return $response->withStatus(302)->withHeader('Location', $this->adminPath);
        } catch (\Throwable $e) {
            return $this->twig->render($response->withStatus(500), '@admin/setup.twig', [
                'error' => 'Vytvoření účtu selhalo: ' . $e->getMessage(),
                'csrf'  => $this->csrfFields($request),
            ]);
        }
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

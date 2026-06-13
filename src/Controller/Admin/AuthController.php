<?php

declare(strict_types=1);

namespace Station0\Controller\Admin;

use Delight\Auth\AmbiguousUsernameException;
use Delight\Auth\Auth;
use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\InvalidPasswordException;
use Delight\Auth\InvalidSelectorTokenPairException;
use Delight\Auth\ResetDisabledException;
use Delight\Auth\TokenExpiredException;
use Delight\Auth\TooManyRequestsException;
use Delight\Auth\UnknownUsernameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Station0\Service\MailerService;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Twig $twig,
        private readonly Guard $csrf,
        private readonly MailerService $mailer,
        private readonly string $baseUrl,
        private readonly string $adminPath,
        private readonly array $lang = [],
    ) {}

    private function t(string $key): string
    {
        return $this->lang[$key] ?? $key;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withStatus(302)->withHeader('Location', $this->adminPath);
        }
        $params = $request->getQueryParams();
        return $this->twig->render($response, '@admin/login.twig', [
            'error' => null,
            'reset' => isset($params['reset']),
            'csrf' => $this->csrfFields($request),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $error = null;

        try {
            $this->auth->loginWithUsername($username, $password, 60 * 60 * 24 * 14);
            return $response->withStatus(302)->withHeader('Location', $this->adminPath);
        } catch (UnknownUsernameException) {
            try {
                $this->auth->login($username, $password, 60 * 60 * 24 * 14);
                return $response->withStatus(302)->withHeader('Location', $this->adminPath);
            } catch (\Throwable) {
                $error = $this->t('err_invalid_credentials');
            }
        } catch (AmbiguousUsernameException | InvalidPasswordException | InvalidEmailException) {
            $error = $this->t('err_invalid_credentials');
        } catch (TooManyRequestsException) {
            $error = $this->t('err_too_many_attempts');
        } catch (\Throwable) {
            $error = $this->t('err_login_failed');
        }

        return $this->twig->render($response->withStatus(401), '@admin/login.twig', [
            'error' => $error,
            'csrf' => $this->csrfFields($request),
        ]);
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

    public function showForgotPassword(Request $request, Response $response): Response
    {
        return $this->twig->render($response, '@admin/forgot-password.twig', [
            'csrf' => $this->csrfFields($request),
            'sent' => false,
            'error' => null,
        ]);
    }

    public function forgotPassword(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $error = null;

        try {
            $this->auth->forgotPassword($email, function (string $selector, string $token) use ($email) {
                $link = $this->baseUrl . $this->adminPath . '/reset-password?selector=' . rawurlencode($selector) . '&token=' . rawurlencode($token);
                $body = '<p>' . htmlspecialchars($this->t('email_reset_intro')) . '</p>'
                    . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
                    . '<p>' . htmlspecialchars($this->t('email_reset_ignore')) . '</p>';
                $this->mailer->send($email, $this->t('email_reset_subject'), $body);
            });
            return $this->twig->render($response, '@admin/forgot-password.twig', [
                'csrf' => $this->csrfFields($request),
                'sent' => true,
                'error' => null,
            ]);
        } catch (InvalidEmailException | EmailNotVerifiedException) {
            $error = $this->t('err_email_not_found');
        } catch (ResetDisabledException) {
            $error = $this->t('err_reset_disabled');
        } catch (TooManyRequestsException) {
            $error = $this->t('err_too_many_attempts');
        } catch (\Throwable) {
            $error = $this->t('err_email_send_failed');
        }

        return $this->twig->render($response->withStatus(422), '@admin/forgot-password.twig', [
            'csrf' => $this->csrfFields($request),
            'sent' => false,
            'error' => $error,
        ]);
    }

    public function showResetPassword(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $selector = (string) ($params['selector'] ?? '');
        $token    = (string) ($params['token'] ?? '');

        if ($selector === '' || $token === '') {
            return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/forgot-password');
        }

        $error = null;
        try {
            $this->auth->canResetPasswordOrThrow($selector, $token);
        } catch (InvalidSelectorTokenPairException | TokenExpiredException) {
            $error = $this->t('err_reset_link_invalid');
        } catch (\Throwable) {
            $error = $this->t('err_reset_verify_error');
        }

        return $this->twig->render($response, '@admin/reset-password.twig', [
            'csrf'     => $this->csrfFields($request),
            'selector' => $selector,
            'token'    => $token,
            'error'    => $error,
        ]);
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $selector = (string) ($data['selector'] ?? '');
        $token    = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $error    = null;

        try {
            $this->auth->resetPassword($selector, $token, $password);
            return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/login?reset=1');
        } catch (InvalidSelectorTokenPairException | TokenExpiredException) {
            $error = $this->t('err_reset_link_invalid');
        } catch (ResetDisabledException) {
            $error = $this->t('err_reset_disabled');
        } catch (InvalidPasswordException) {
            $error = $this->t('err_password_min');
        } catch (TooManyRequestsException) {
            $error = $this->t('err_too_many_attempts');
        } catch (\Throwable) {
            $error = $this->t('err_reset_failed');
        }

        return $this->twig->render($response->withStatus(422), '@admin/reset-password.twig', [
            'csrf'     => $this->csrfFields($request),
            'selector' => $selector,
            'token'    => $token,
            'error'    => $error,
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        try {
            $this->auth->logOut();
        } catch (\Throwable) {
        }
        return $response->withStatus(302)->withHeader('Location', $this->adminPath . '/login');
    }
}

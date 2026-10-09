<?php

declare(strict_types=1);

namespace Station0\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Station0\Service\AccessPolicy;
use Station0\Service\ActionContext;
use Station0\Service\ActionState;
use Station0\Service\LoginLinks;
use Station0\Service\MailerService;
use Station0\Service\Members;
use Station0\Service\RateLimiter;
use Station0\Service\Visitor;

/**
 * Member sign-in on the public site, enabled by `access.loginPath`
 * (e.g. '/login'):
 *
 *   GET  {loginPath}            the sign-in page (site template login.twig)
 *   POST {loginPath}            e-mail + password (+ remember)
 *   POST {loginPath}/link       e-mail a one-time sign-in link
 *   GET  {loginPath}/link       follow that link (?selector=…&token=…)
 *   POST {loginPath}/password   signed-in member sets a new password
 *   POST {loginPath}/logout     sign out
 *
 * Outcomes are flashed as codes into the `login` state, which the page gets
 * as `login` (error, notice, email, retryAfter, next) — the template words them.
 *   errors:  invalid, throttled, link_invalid, password_short,
 *            password_mismatch, not_signed_in
 *   notices: link_sent, password_set, signed_out
 */
final class MemberAuthController
{
    public const STATE = 'login';
    private const LINK_TTL = 1200;

    /** @param array<string, mixed> $session the session array (by reference) */
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly Visitor $visitor,
        private readonly Members $members,
        private readonly LoginLinks $links,
        private readonly RateLimiter $limiter,
        private readonly MailerService $mailer,
        private readonly Twig $twig,
        private readonly LoggerInterface $logger,
        private readonly array $lang,
        private readonly string $baseUrl,
        private readonly string $siteName,
        private readonly string $templatesPath,
        private array &$session,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $state = $this->state()->pull();
        $next  = ActionContext::localPath((string) ($request->getQueryParams()['next'] ?? '')) ?? ($state['next'] ?? null);
        $template = is_file(rtrim($this->templatesPath, '/') . '/login.twig') ? 'login.twig' : '@admin/member-login.twig';

        return $this->twig->render($response, $template, [
            'login' => [
                'error'      => $state['error'] ?? null,
                'notice'     => $state['notice'] ?? null,
                'email'      => $state['email'] ?? '',
                'retryAfter' => $state['retryAfter'] ?? 0,
                'next'       => $next,
                'paths'      => $this->paths(),
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function login(Request $request, Response $response): Response
    {
        $data     = (array) $request->getParsedBody();
        $email    = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $remember = !empty($data['remember']);
        $next     = ActionContext::localPath((string) ($data['next'] ?? ''));
        $ipKey    = 'member-login:ip:' . $this->ip($request);
        $mailKey  = 'member-login:email:' . mb_strtolower($email);

        $wait = max($this->limiter->blocked($ipKey), $this->limiter->blocked($mailKey));
        if ($wait > 0) {
            return $this->fail('throttled', ['email' => $email, 'retryAfter' => $wait, 'next' => $next]);
        }

        $member = $email !== '' && $password !== '' ? $this->members->verify($email, $password) : null;
        if ($member === null) {
            $this->limiter->hit($ipKey, 20, 900);
            $this->limiter->hit($mailKey, 5, 900);
            return $this->fail('invalid', ['email' => $email, 'next' => $next]);
        }

        $this->limiter->reset($mailKey);
        $this->visitor->signInMember($member['id'], $remember);
        return $this->redirect($next ?? $this->policy->redirect());
    }

    public function requestLink(Request $request, Response $response): Response
    {
        $data  = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $next  = ActionContext::localPath((string) ($data['next'] ?? ''));

        $ip   = $this->limiter->hit('member-link:ip:' . $this->ip($request), 10, 3600);
        $mail = $this->limiter->hit('member-link:email:' . mb_strtolower($email), 3, 3600);
        if (!$ip['allowed'] || !$mail['allowed']) {
            return $this->fail('throttled', ['email' => $email, 'retryAfter' => max($ip['retryAfter'], $mail['retryAfter']), 'next' => $next]);
        }

        $member = $this->members->findByEmail($email);
        if ($member !== null && $member['active']) {
            $link = $this->links->create($member['id'], self::LINK_TTL);
            $url  = rtrim($this->baseUrl, '/') . $this->paths()['link']
                . '?selector=' . rawurlencode($link['selector']) . '&token=' . rawurlencode($link['token']);
            try {
                $this->sendLink($member, $url);
            } catch (\Throwable $e) {
                // Same answer as for an unknown address — log for the admin.
                $this->logger->error('Member sign-in link e-mail failed: ' . $e->getMessage(), ['email' => $member['email']]);
            }
        }

        // Never reveal whether the address has an account.
        $this->state()->flash('notice', 'link_sent');
        $this->state()->flash('email', $email);
        if ($next !== null) {
            $this->state()->flash('next', $next);
        }
        return $this->redirect($this->paths()['login']);
    }

    public function followLink(Request $request, Response $response): Response
    {
        $q      = $request->getQueryParams();
        $userId = $this->links->consume((string) ($q['selector'] ?? ''), (string) ($q['token'] ?? ''));
        $member = $userId !== null ? $this->members->findById($userId) : null;
        if ($member === null || !$member['active']) {
            return $this->fail('link_invalid');
        }
        $this->visitor->signInMember($member['id'], true);
        return $this->redirect($this->policy->redirect());
    }

    public function setPassword(Request $request, Response $response): Response
    {
        $data     = (array) $request->getParsedBody();
        $password = (string) ($data['password'] ?? '');
        $confirm  = (string) ($data['password_confirm'] ?? $password);
        $current  = $this->visitor->current();
        $back     = ActionContext::localPath((string) ($data['back'] ?? '')) ?? $this->paths()['login'];

        if ($current === null || $current['kind'] !== 'member' || $current['userId'] === null) {
            return $this->fail('not_signed_in');
        }
        if (mb_strlen($password) < Members::MIN_PASSWORD) {
            $this->state()->flash('error', 'password_short');
            return $this->redirect($back);
        }
        if (!hash_equals($password, $confirm)) {
            $this->state()->flash('error', 'password_mismatch');
            return $this->redirect($back);
        }

        $this->members->setPassword($current['userId'], $password);
        $this->state()->flash('notice', 'password_set');
        return $this->redirect($back);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->visitor->signOut();
        $this->state()->flash('notice', 'signed_out');
        return $this->redirect($this->policy->redirect());
    }

    /** @return array{login: string, link: string, password: string, logout: string} */
    public function paths(): array
    {
        $base = (string) $this->policy->loginPath();
        return [
            'login'    => $base,
            'link'     => $base . '/link',
            'password' => $base . '/password',
            'logout'   => $base . '/logout',
        ];
    }

    private function sendLink(array $member, string $url): void
    {
        $vars = ['url' => $url, 'member' => $member, 'minutes' => intdiv(self::LINK_TTL, 60), 'site' => $this->siteName];
        $custom = rtrim($this->templatesPath, '/') . '/emails/login-link.twig';
        if (is_file($custom)) {
            $env     = $this->twig->getEnvironment();
            $tpl     = $env->load('emails/login-link.twig');
            $subject = trim($tpl->hasBlock('subject') ? $tpl->renderBlock('subject', $vars) : $this->siteName);
            $body    = $tpl->hasBlock('body') ? $tpl->renderBlock('body', $vars) : $tpl->render($vars);
        } else {
            $subject = sprintf($this->t('member_link_subject'), $this->siteName);
            $body    = '<p>' . htmlspecialchars(sprintf($this->t('member_link_intro'), $this->siteName, $vars['minutes'])) . '</p>'
                . '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>'
                . '<p>' . htmlspecialchars($this->t('member_link_ignore')) . '</p>';
        }
        $this->mailer->send($member['email'], $subject, $body);
    }

    private function fail(string $error, array $extra = []): Response
    {
        $this->state()->flash('error', $error);
        foreach ($extra as $k => $v) {
            if ($v !== null && $v !== '') {
                $this->state()->flash($k, $v);
            }
        }
        return $this->redirect($this->paths()['login']);
    }

    private function state(): ActionState
    {
        return new ActionState($this->session, self::STATE);
    }

    private function redirect(string $path): Response
    {
        return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function ip(Request $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function t(string $key): string
    {
        return $this->lang[$key] ?? $key;
    }
}

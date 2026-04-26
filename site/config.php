<?php

return function (string $station0Root, string $siteRoot): array {
    return [
        'name'      => 'Station0',
        'baseUrl'   => $_ENV['BASE_URL'] ?? 'http://localhost:8080',
        'debug'     => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'adminPath' => rtrim($_ENV['ADMIN_PATH'] ?? '/admin', '/'),
        'paths' => [
            'content'        => $siteRoot . '/content',
            'templates'      => $siteRoot . '/templates',
            'adminTemplates' => $station0Root . '/admin/templates',
            'cache'          => $station0Root . '/writable/cache',
            'sessions'       => $station0Root . '/writable/sessions',
            'logs'           => $station0Root . '/writable/logs',
            'db'             => $station0Root . '/writable/db.sqlite',
            'uploads'        => $station0Root . '/public/uploads',
        ],
        'uploadsUrl' => '/uploads',
        'mail' => [
            'host'       => $_ENV['MAIL_HOST']      ?? 'localhost',
            'port'       => (int) ($_ENV['MAIL_PORT']  ?? 587),
            'username'   => $_ENV['MAIL_USERNAME']  ?? '',
            'password'   => $_ENV['MAIL_PASSWORD']  ?? '',
            'from'       => $_ENV['MAIL_FROM']      ?? 'noreply@localhost',
            'fromName'   => $_ENV['MAIL_FROM_NAME'] ?? 'Station0',
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        ],
        'session' => [
            'name'           => 'station0',
            'cookieLifetime' => 0,
            'sameSite'       => 'Lax',
            'secure'         => filter_var($_ENV['HTTPS'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ],
    ];
};

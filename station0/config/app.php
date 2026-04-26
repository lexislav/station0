<?php

return [
    'name' => 'Station0',
    'baseUrl' => $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'adminPath' => rtrim($_ENV['APP_ADMIN_PATH'] ?? '/admin', '/'),
    'paths' => [
        'content' => dirname(__DIR__) . '/content',
        'templates' => dirname(__DIR__) . '/templates',
        'cache' => dirname(__DIR__) . '/writable/cache',
        'sessions' => dirname(__DIR__) . '/writable/sessions',
        'logs' => dirname(__DIR__) . '/writable/logs',
        'db' => dirname(__DIR__) . '/writable/db.sqlite',
        'uploads' => dirname(__DIR__) . '/public/uploads',
    ],
    'uploadsUrl' => '/uploads',
    'mail' => [
        'host'     => $_ENV['MAIL_HOST']      ?? 'localhost',
        'port'     => (int) ($_ENV['MAIL_PORT']  ?? 587),
        'username' => $_ENV['MAIL_USERNAME']  ?? '',
        'password' => $_ENV['MAIL_PASSWORD']  ?? '',
        'from'     => $_ENV['MAIL_FROM']      ?? 'noreply@localhost',
        'fromName' => $_ENV['MAIL_FROM_NAME'] ?? 'Station0',
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
    ],
    'session' => [
        'name' => 'station0',
        'cookieLifetime' => 0,
        'sameSite' => 'Lax',
        'secure' => filter_var($_ENV['APP_HTTPS'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
];

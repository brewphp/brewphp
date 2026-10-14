<?php

declare(strict_types=1);

return [
    'DEBUG' => true,
    'SECRET_KEY' => 'test-secret-key-that-is-long-enough-for-the-checks-0123456789',
    'ALLOWED_HOSTS' => [],
    'INSTALLED_APPS' => ['demo'],
    'MIDDLEWARE' => [BrewPHP\Middleware\SecurityHeadersMiddleware::class],
    'TEMPLATES' => ['DIRS' => ['templates']],
];

<?php

declare(strict_types=1);

use Apps\Demo\Middleware\TagMiddleware;

return [
    path('tagged/', fn (): string => 'tagged', name: 'tagged', middleware: [TagMiddleware::class]),
    path('posts/<slug:slug>/', fn (string $slug): string => "post $slug", name: 'post'),
];

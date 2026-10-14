<?php

declare(strict_types=1);

use Apps\Demo\ArticleView;
use Apps\Demo\GreetingController;
use BrewPHP\Http\Request;

return [
    path('', Apps\Demo\home(...), name: 'home'),
    path('hello/<str:name>/', [GreetingController::class, 'hello'], name: 'hello'),
    path('articles/<int:id>/', ArticleView::class, name: 'article'),
    path('json/', fn (Request $request): array => ['ok' => true, 'q' => $request->query('q')], name: 'json'),
    path('only-post/', fn (): string => 'posted', name: 'only-post', methods: ['POST']),
    path('boom/', fn (): string => throw new RuntimeException('Kaboom'), name: 'boom'),
    path('forbidden/', fn (): never => abort(403, 'Members only'), name: 'forbidden'),
    path('demo/', include_urls('apps/demo/urls.php', namespace: 'demo')),
];

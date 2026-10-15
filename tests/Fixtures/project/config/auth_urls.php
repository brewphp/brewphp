<?php

declare(strict_types=1);

use BrewPHP\Auth\Middleware\LoginRequired;
use BrewPHP\Auth\Middleware\PermissionRequired;
use BrewPHP\Auth\Urls;
use BrewPHP\Http\Request;
use BrewPHP\Security\CsrfExempt;
use BrewPHP\Sessions\Messages;

// The URLconf of the auth, session and CSRF tests.
return [
    path('accounts/', include_urls(Urls::patterns())),
    path('secret/', static fn (Request $request): string => 'secret of ' . $request->user()->getUsername(), name: 'secret', middleware: [LoginRequired::class]),
    path('tags/new/', static fn (): string => 'can add tags', name: 'tag-add', middleware: [new PermissionRequired('demo.add_tag')]),
    path('echo/', static fn (Request $request): string => 'posted ' . $request->post('value', ''), name: 'echo', methods: ['GET', 'POST']),
    path('hook/', #[CsrfExempt] static fn (): string => 'hook ok', name: 'hook', methods: ['POST']),
    path('remember/', static function (Request $request): string {
        $request->session()->set('color', $request->query('color', 'blue'));
        Messages::success($request, 'Saved <b>' . $request->query('color', 'blue') . '</b>.');

        return 'remembered';
    }, name: 'remember'),
    path('recall/', static fn (Request $request): string => (string) $request->session()->get('color', 'none'), name: 'recall'),
    path('flash/', static fn (Request $request): string => render_to_string('demo/messages.html', [], $request), name: 'flash'),
    path('token/', static fn (Request $request): string => render_to_string('demo/token.html', [], $request), name: 'token'),
];

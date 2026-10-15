<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Views;

use BrewPHP\Auth\Middleware\LoginRequired;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;

/**
 * The page shown after a password change.
 */
class PasswordChangeDoneView extends AuthView
{
    public string $templateName = 'registration/password_change_done.html';

    public function dispatch(Request $request, \Closure $call): mixed
    {
        if (!$request->user()->isAuthenticated()) {
            return LoginRequired::redirectToLogin($request);
        }

        return parent::dispatch($request, $call);
    }

    public function get(Request $request): Response
    {
        return $this->page($request, ['title' => 'Password change successful']);
    }
}

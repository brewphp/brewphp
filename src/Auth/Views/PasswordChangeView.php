<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Views;

use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Forms\PasswordChangeForm;
use BrewPHP\Auth\Middleware\LoginRequired;
use BrewPHP\Auth\Models\User;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;

/**
 * Lets a logged-in user change their password. Other sessions of the user
 * are logged out; this one stays logged in.
 */
class PasswordChangeView extends AuthView
{
    public string $templateName = 'registration/password_change_form.html';

    /** Where to go afterwards: a path or a route name. */
    public string $successUrl = 'password_change_done';

    public function dispatch(Request $request, \Closure $call): mixed
    {
        if (!$request->user() instanceof User) {
            return LoginRequired::redirectToLogin($request);
        }

        return parent::dispatch($request, $call);
    }

    public function get(Request $request): Response
    {
        return $this->show(new PasswordChangeForm(user: $this->user($request)));
    }

    public function post(Request $request): Response
    {
        $user = $this->user($request);
        $form = new PasswordChangeForm((array) $request->post(), user: $user);
        if (!$form->isValid()) {
            return $this->show($form);
        }
        $form->save();
        Auth::updateSessionAuthHash($request, $user);

        return $this->redirectTo($this->nextUrl($request, $this->successUrl) ?? '/');
    }

    private function show(PasswordChangeForm $form): Response
    {
        return $this->page($this->request, ['form' => $form, 'title' => 'Password change']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new \LogicException('Not logged in.');
    }
}

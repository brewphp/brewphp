<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Views;

use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Forms\AuthenticationForm;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;

/**
 * Shows the login form and logs the user in. After login the user goes to
 * `next` (when it is on this site) or LOGIN_REDIRECT_URL.
 *
 *     path('login/', LoginView::asView(['templateName' => 'accounts/login.html']), name: 'login'),
 */
class LoginView extends AuthView
{
    public string $templateName = 'registration/login.html';

    /** @var class-string<AuthenticationForm> */
    public string $formClass = AuthenticationForm::class;

    /** Send users who are already logged in to the next page. */
    public bool $redirectAuthenticatedUser = false;

    public function get(Request $request): Response
    {
        if ($this->redirectAuthenticatedUser && $request->user()->isAuthenticated()) {
            return $this->redirectTo($this->nextUrl($request, $this->setting('LOGIN_REDIRECT_URL', '/')) ?? '/');
        }

        return $this->show($request, new $this->formClass(request: $request));
    }

    public function post(Request $request): Response
    {
        $form = new $this->formClass((array) $request->post(), request: $request);
        $user = $form->getUser();
        if ($user === null) {
            return $this->show($request, $form);
        }
        Auth::login($request, $user);

        return $this->redirectTo($this->nextUrl($request, $this->setting('LOGIN_REDIRECT_URL', '/')) ?? '/');
    }

    private function show(Request $request, AuthenticationForm $form): Response
    {
        $next = $request->post('next') ?? $request->query('next');

        return $this->page($request, [
            'form' => $form,
            'next' => is_string($next) && $this->nextUrl($request, null) !== null ? $next : '',
            'title' => 'Log in',
        ]);
    }
}

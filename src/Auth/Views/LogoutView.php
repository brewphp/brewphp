<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Views;

use BrewPHP\Auth\Auth;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;

/**
 * Logs the user out. Only POST is accepted (with the CSRF token): a GET
 * link could log people out from any page that embeds it.
 *
 *     <form method="post" action="{% url 'logout' %}">{% csrf_token %}<button>Log out</button></form>
 *
 * Then redirects to `next`, LOGOUT_REDIRECT_URL, or shows the
 * `registration/logged_out.html` template.
 */
class LogoutView extends AuthView
{
    public string $templateName = 'registration/logged_out.html';

    public function post(Request $request): Response
    {
        Auth::logout($request);
        $next = $this->nextUrl($request, $this->setting('LOGOUT_REDIRECT_URL'));
        if ($next !== null) {
            return $this->redirectTo($next);
        }

        return $this->page($request, ['title' => 'Logged out']);
    }
}

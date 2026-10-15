<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use Closure;

/**
 * Makes `$request->user()` available: the logged-in User or an
 * AnonymousUser, loaded from the session on first use. Put it after
 * SessionMiddleware.
 */
final class AuthenticationMiddleware implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        if (!$request->hasSession()) {
            throw new ImproperlyConfigured('AuthenticationMiddleware needs SessionMiddleware before it in MIDDLEWARE.');
        }
        $request->setAttribute('_user_resolver', static fn (Request $request): Authenticatable => Auth::user($request));

        return $next($request);
    }
}

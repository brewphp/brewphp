<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Middleware;

use BrewPHP\Core\Application;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use Closure;

/**
 * Sends visitors who are not logged in to LOGIN_URL, with `?next=` set to
 * the page they wanted:
 *
 *     path('dashboard/', dashboard(...), middleware: [LoginRequired::class]),
 *     group('account/', [...], middleware: [LoginRequired::class]),
 */
class LoginRequired implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        if (!$request->user()->isAuthenticated()) {
            return self::redirectToLogin($request);
        }

        return $next($request);
    }

    /**
     * A redirect to LOGIN_URL (a path or a route name) with `?next=`.
     */
    public static function redirectToLogin(Request $request, ?string $loginUrl = null): RedirectResponse
    {
        $loginUrl ??= Application::getInstance()->settings()->string('LOGIN_URL', '/accounts/login/');
        if (!str_starts_with($loginUrl, '/') && !preg_match('#^https?://#', $loginUrl)) {
            $loginUrl = reverse($loginUrl);
        }
        $separator = str_contains($loginUrl, '?') ? '&' : '?';

        return new RedirectResponse($loginUrl . $separator . http_build_query(['next' => $request->fullPath()]));
    }
}

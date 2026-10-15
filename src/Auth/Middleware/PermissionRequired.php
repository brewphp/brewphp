<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Middleware;

use BrewPHP\Http\PermissionDenied;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use Closure;

/**
 * Only lets users with every given permission through. Visitors who are
 * not logged in go to the login page; logged-in users without the
 * permission get a 403.
 *
 *     path('posts/new/', new_post(...), middleware: [new PermissionRequired('blog.add_post')]),
 */
final class PermissionRequired implements Middleware
{
    /** @var list<string> */
    private readonly array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = array_values($permissions);
    }

    public function process(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user->isAuthenticated()) {
            return LoginRequired::redirectToLogin($request);
        }
        if (!$user->hasPerms($this->permissions)) {
            throw new PermissionDenied('You do not have permission to view this page.');
        }

        return $next($request);
    }
}

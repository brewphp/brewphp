<?php

declare(strict_types=1);

namespace Apps\Demo\Middleware;

use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use Closure;

final class TagMiddleware implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        return $next($request)->header('X-Tag', 'demo');
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Middleware;

use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use Closure;

/**
 * Middleware wraps the handling of every request. Call `$next($request)`
 * to continue to the view (and any inner middleware) and get its response.
 *
 *     final class TimingMiddleware implements Middleware
 *     {
 *         public function process(Request $request, Closure $next): Response
 *         {
 *             $start = microtime(true);
 *             $response = $next($request);
 *
 *             return $response->header('X-Time', (string) round(microtime(true) - $start, 3));
 *         }
 *     }
 */
interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function process(Request $request, Closure $next): Response;
}

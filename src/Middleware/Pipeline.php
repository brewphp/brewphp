<?php

declare(strict_types=1);

namespace BrewPHP\Middleware;

use BrewPHP\Core\Container;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use Closure;

/**
 * Runs a request through a list of middleware, outermost first, and then
 * into a final handler.
 *
 * Each entry can be a Middleware class name (built by the container), a
 * Middleware instance, or a closure `fn (Request $request, Closure $next): Response`.
 */
final class Pipeline
{
    /**
     * @param list<mixed> $middleware
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $middleware,
    ) {
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function handle(Request $request, Closure $handler): Response
    {
        $next = $handler;
        foreach (array_reverse($this->middleware) as $entry) {
            $middleware = $this->resolve($entry);
            $next = static fn (Request $request): Response => $middleware($request, $next);
        }

        return $next($request);
    }

    /**
     * @return Closure(Request, Closure): Response
     */
    private function resolve(mixed $entry): Closure
    {
        if (is_string($entry) && class_exists($entry)) {
            $entry = $this->container->make($entry);
        }
        if ($entry instanceof Middleware) {
            return $entry->process(...);
        }
        if ($entry instanceof Closure) {
            return static function (Request $request, Closure $next) use ($entry): Response {
                $response = $entry($request, $next);
                if (!$response instanceof Response) {
                    throw new \UnexpectedValueException('Middleware closures must return a Response.');
                }

                return $response;
            };
        }

        throw new ImproperlyConfigured(sprintf(
            'Invalid middleware %s: use a class implementing %s or a closure.',
            is_string($entry) ? '"' . $entry . '"' : get_debug_type($entry),
            Middleware::class,
        ));
    }
}

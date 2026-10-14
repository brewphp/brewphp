<?php

declare(strict_types=1);

namespace BrewPHP\Views;

use BrewPHP\Core\Container;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\JsonResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use Closure;

/**
 * Calls the view of a matched route and turns its return value into a
 * Response.
 *
 * A view can be:
 * - a closure or function name: `fn (Request $request) => ...`, `'home'`;
 * - a `[Controller::class, 'method']` pair (the controller is built by the
 *   container, so its constructor can ask for services);
 * - the class name of a `View` subclass, or an instance from `asView()`.
 *
 * Arguments are filled by name from the URL parameters, and by type: a
 * `Request` parameter gets the current request, other classes come from
 * the container.
 *
 * A view may return a Response, a string (sent as HTML), or an array or
 * JsonSerializable (sent as JSON).
 */
final class ViewResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function call(mixed $view, Request $request, array $parameters = []): Response
    {
        $invoke = function (callable $callable) use ($request, $parameters): mixed {
            $reflection = new \ReflectionFunction(Closure::fromCallable($callable));

            return $callable(...$this->container->resolveArguments($reflection, $parameters, [Request::class => $request]));
        };

        if (is_string($view) && is_subclass_of($view, View::class)) {
            $view = $this->container->make($view);
        }
        if ($view instanceof View) {
            $view = clone $view;
            $view->setup($request, $parameters);

            return self::toResponse($view->dispatch($request, $invoke), $view);
        }

        if (is_array($view) && count($view) === 2 && is_string($view[0] ?? null) && is_string($view[1] ?? null)) {
            [$class, $method] = $view;
            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new ImproperlyConfigured(sprintf('View %s::%s() does not exist.', $class, $method));
            }
            if (!(new \ReflectionMethod($class, $method))->isStatic()) {
                $view = [$this->container->make($class), $method];
            }
        }

        if (!is_callable($view)) {
            throw new ImproperlyConfigured(sprintf('Route view %s is not callable.', self::describe($view)));
        }

        return self::toResponse($invoke($view), $view);
    }

    /**
     * Convert whatever a view returned into a Response.
     */
    public static function toResponse(mixed $result, mixed $view = null): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_string($result), $result instanceof \Stringable => new Response((string) $result),
            is_array($result), $result instanceof \JsonSerializable => new JsonResponse($result),
            default => throw new \UnexpectedValueException(sprintf(
                'View %s returned %s; it must return a Response, a string or an array.',
                self::describe($view),
                get_debug_type($result),
            )),
        };
    }

    /**
     * A readable name for a view, e.g. "App\PostController::detail".
     */
    public static function describe(mixed $view): string
    {
        return match (true) {
            $view instanceof Closure => self::describeClosure($view),
            $view instanceof View => $view::class,
            is_string($view) => $view,
            is_array($view) && count($view) === 2 => (is_object($view[0]) ? $view[0]::class : (is_string($view[0]) ? $view[0] : '?'))
                . '::' . (is_string($view[1]) ? $view[1] : '?'),
            is_object($view) => $view::class,
            default => get_debug_type($view),
        };
    }

    private static function describeClosure(Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);
        if (!str_contains($reflection->getName(), '{closure')) {
            $class = $reflection->getClosureScopeClass()?->getName();

            return ($class !== null && $reflection->getClosureThis() !== null ? $class . '::' : '') . $reflection->getName();
        }

        return sprintf('Closure (%s:%d)', basename((string) $reflection->getFileName()), (int) $reflection->getStartLine());
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Views;

use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use Closure;

/**
 * Base class for class-based views. Define a method per HTTP verb:
 *
 *     final class ContactView extends View
 *     {
 *         public function get(Request $request): Response { ... }
 *         public function post(Request $request): Response { ... }
 *     }
 *
 *     path('contact/', ContactView::class, name: 'contact'),
 *
 * Handler methods get their arguments like function views: the request,
 * URL parameters by name, and services by type. Any other verb gets a
 * 405 response; OPTIONS is answered automatically.
 */
abstract class View
{
    public const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    protected Request $request;

    /** @var array<string, mixed> URL parameters of the current request */
    protected array $params = [];

    /**
     * A configured instance for a URLconf: public properties are set from
     * $properties. Each request gets its own copy.
     *
     *     path('about/', AboutView::asView(['title' => 'About us'])),
     *
     * @param array<string, mixed> $properties
     */
    public static function asView(array $properties = []): static
    {
        $reflection = new \ReflectionClass(static::class);
        if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new \LogicException(sprintf('%s::asView() needs a view without required constructor arguments; use the class name instead.', static::class));
        }
        $view = $reflection->newInstance();
        foreach ($properties as $name => $value) {
            if (!$reflection->hasProperty($name) || !$reflection->getProperty($name)->isPublic()) {
                throw new \InvalidArgumentException(sprintf('%s has no public property "%s".', static::class, $name));
            }
            $view->{$name} = $value;
        }

        return $view;
    }

    /**
     * Called before the handler runs. Override to prepare shared state,
     * remembering to call parent::setup().
     *
     * @param array<string, mixed> $params
     */
    public function setup(Request $request, array $params): void
    {
        $this->request = $request;
        $this->params = $params;
    }

    /**
     * Pick the handler for the request's method and run it through $call,
     * which fills in the handler's arguments. Override to run code around
     * every handler.
     *
     * @param Closure(callable): mixed $call
     */
    public function dispatch(Request $request, Closure $call): mixed
    {
        $method = strtolower($request->method());
        if ($method === 'head' && !method_exists($this, 'head')) {
            $method = 'get';
        }
        if (!in_array($method, self::HTTP_METHODS, true) || !method_exists($this, $method)) {
            throw new MethodNotAllowed($this->allowedMethods());
        }

        /** @var callable $handler */
        $handler = [$this, $method];

        return $call($handler);
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        $allowed = [];
        foreach (self::HTTP_METHODS as $method) {
            if (method_exists($this, $method)) {
                $allowed[] = strtoupper($method);
            }
        }
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return array_values($allowed);
    }

    public function options(): Response
    {
        return new Response('', 204, ['Allow' => implode(', ', $this->allowedMethods())]);
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Routing\Attributes\Route as RouteAttribute;

/**
 * Matches request paths to routes and builds URLs from route names.
 *
 * Routes are tried in the order they are listed; the first match wins.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var array<string, Converter> */
    private static array $customConverters = [];

    /** @var array<string, Converter> */
    private array $converters;

    /**
     * @param array<mixed> $patterns routes, groups or nested arrays of them
     */
    public function __construct(array $patterns = [])
    {
        $this->converters = array_replace(Converter::defaults(), self::$customConverters);
        $this->add($patterns);
    }

    /**
     * Register a path converter for every router, e.g. `<year:y>`.
     */
    public static function registerConverter(string $name, Converter $converter): void
    {
        if (!preg_match('/^[A-Za-z_]\w*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid converter name "%s".', $name));
        }
        self::$customConverters[$name] = $converter;
    }

    /**
     * @param array<mixed> $patterns
     */
    public function add(array $patterns): void
    {
        foreach (RouteGroup::flattenList($patterns) as $route) {
            $this->routes[] = $route;
            if ($route->name !== null && !isset($this->named[$route->name])) {
                $this->named[$route->name] = $route;
            }
        }
    }

    /**
     * Find the route for a method and path.
     *
     * @throws RouteNotFound when no pattern matches
     * @throws MethodNotAllowed when patterns match but not for this method
     */
    public function resolve(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $path = ltrim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->match($path, $this->converters);
            if ($parameters === null) {
                continue;
            }
            if ($route->allowsMethod($method)) {
                return new RouteMatch($route, $parameters);
            }
            array_push($allowed, ...$route->methods);
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            throw new MethodNotAllowed(array_values(array_unique($allowed)));
        }

        throw new RouteNotFound('/' . $path, $this->routes);
    }

    /**
     * Whether any route matches the path, whatever the method.
     */
    public function matches(string $path): bool
    {
        $path = ltrim($path, '/');
        foreach ($this->routes as $route) {
            if ($route->match($path, $this->converters) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the path for a named route: `reverse('blog:post-detail', ['id' => 5])`
     * gives "/blog/posts/5/".
     *
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $query appended as a query string
     */
    public function reverse(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new NoReverseMatch(sprintf(
            'No route named "%s".%s',
            $name,
            $this->suggest($name),
        ));

        $path = $route->reverse($parameters, $this->converters);

        return $query !== [] ? $path . '?' . http_build_query($query) : $path;
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Read `#[Route]` attributes from controller classes.
     *
     * @param class-string ...$classes
     * @return list<Route>
     */
    public static function fromAttributes(string ...$classes): array
    {
        $routes = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $prefixes = array_map(
                static fn (\ReflectionAttribute $a): RouteAttribute => $a->newInstance(),
                $reflection->getAttributes(RouteAttribute::class),
            );
            $prefix = $prefixes[0] ?? null;

            $classRoutes = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $classRoutes[] = Route::path($route->path, [$class, $method->getName()], $route->name, $route->methods, $route->middleware);
                }
            }

            if ($prefix !== null) {
                $classRoutes = (new RouteGroup($classRoutes, [[ltrim($prefix->path, '/'), false]], $prefix->namespace, $prefix->middleware))->flatten();
            }
            array_push($routes, ...$classRoutes);
        }

        return $routes;
    }

    private function suggest(string $name): string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (array_keys($this->named) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best !== null && $bestDistance <= 3 ? sprintf(' Did you mean "%s"?', $best) : '';
    }
}

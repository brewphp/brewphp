<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

/**
 * A list of routes sharing a URL prefix, a namespace for reversing and
 * middleware. Created by `include_urls()` and `group()`.
 */
final class RouteGroup
{
    /**
     * @param array<mixed> $routes Route, RouteGroup or nested arrays of them
     * @param list<array{0: string, 1: bool}> $segments
     * @param list<mixed> $middleware
     */
    public function __construct(
        public readonly array $routes,
        public readonly array $segments = [],
        public readonly ?string $namespace = null,
        public readonly array $middleware = [],
    ) {
    }

    /**
     * Copy of this group mounted under another prefix (what `path()` does
     * when it is given a group instead of a view).
     *
     * @param list<mixed> $middleware
     */
    public function mount(string $pattern, bool $isRegex = false, array $middleware = []): self
    {
        $segment = [$isRegex ? $pattern : ltrim($pattern, '/'), $isRegex];

        return new self(
            $this->routes,
            array_merge([$segment], $this->segments),
            $this->namespace,
            array_merge($middleware, $this->middleware),
        );
    }

    /**
     * Every route in the group with prefix, namespace and middleware applied.
     *
     * @return list<Route>
     */
    public function flatten(): array
    {
        return self::flattenList($this->routes, $this->segments, $this->namespace, $this->middleware);
    }

    /**
     * @param array<mixed> $items
     * @param list<array{0: string, 1: bool}> $segments
     * @param list<mixed> $middleware
     * @return list<Route>
     */
    public static function flattenList(array $items, array $segments = [], ?string $namespace = null, array $middleware = []): array
    {
        $routes = [];
        foreach ($items as $item) {
            if ($item instanceof Route) {
                $routes[] = $item->prefixed($segments, $namespace, $middleware);
            } elseif ($item instanceof self) {
                foreach ($item->flatten() as $route) {
                    $routes[] = $route->prefixed($segments, $namespace, $middleware);
                }
            } elseif (is_array($item)) {
                array_push($routes, ...self::flattenList($item, $segments, $namespace, $middleware));
            } else {
                throw new \BrewPHP\Core\ImproperlyConfigured(sprintf(
                    'A URLconf must contain routes made with path() or re_path(), got %s.',
                    get_debug_type($item),
                ));
            }
        }

        return $routes;
    }
}

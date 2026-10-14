<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

/**
 * The result of resolving a path: the route and its URL parameters.
 * Available in views as `$request->attribute('route')`.
 */
final class RouteMatch
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters,
    ) {
    }

    public function name(): ?string
    {
        return $this->route->name;
    }
}

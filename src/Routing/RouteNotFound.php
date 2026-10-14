<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

use BrewPHP\Http\Http404;

/**
 * No route matched the path. Carries the patterns that were tried, which
 * the debug 404 page lists.
 */
final class RouteNotFound extends Http404
{
    /**
     * @param list<Route> $tried
     */
    public function __construct(
        public readonly string $path,
        public readonly array $tried,
    ) {
        parent::__construct(sprintf('No route matches "%s".', $path));
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Routing\Attributes;

use Attribute;

/**
 * Declare a route on a controller method instead of in `urls.php`.
 * On the class, it sets a prefix (and namespace) for every method route.
 *
 *     #[Route('posts/', namespace: 'posts')]
 *     final class PostController
 *     {
 *         #[Route('', name: 'list')]
 *         public function list(Request $request): Response { ... }
 *
 *         #[Route('<int:id>/', name: 'detail', methods: ['GET'])]
 *         public function detail(Request $request, int $id): Response { ... }
 *     }
 *
 * Register them in a URLconf with `...routes_from(PostController::class)`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param list<string> $methods
     * @param list<mixed> $middleware
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
        public readonly array $methods = [],
        public readonly array $middleware = [],
        public readonly ?string $namespace = null,
    ) {
    }
}

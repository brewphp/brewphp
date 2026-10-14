<?php

declare(strict_types=1);

/*
 * Global helper functions. Each one is a thin shortcut to a framework
 * class, so you can always use the class directly instead.
 */

use BrewPHP\Core\Application;
use BrewPHP\Core\Env;
use BrewPHP\Http\BadRequest;
use BrewPHP\Http\Http404;
use BrewPHP\Http\HttpException;
use BrewPHP\Http\JsonResponse;
use BrewPHP\Http\PermissionDenied;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Routing\Route;
use BrewPHP\Routing\RouteGroup;
use BrewPHP\Routing\Router;

// Application -----------------------------------------------------------------

if (!function_exists('app')) {
    /**
     * The application, or a service from its container: `app(Router::class)`.
     */
    function app(?string $id = null): mixed
    {
        $app = Application::getInstance();

        return $id === null ? $app : $app->get($id);
    }
}

if (!function_exists('settings')) {
    /**
     * Read a setting: `settings('DEBUG')`, `settings('TEMPLATES.DIRS')`.
     */
    function settings(string $key, mixed $default = null): mixed
    {
        return Application::getInstance()->settings()->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable (from the server or `.env`).
     * "true", "false", "null" and "empty" become PHP values.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string ...$parts): string
    {
        return Application::getInstance()->basePath(...$parts);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string ...$parts): string
    {
        return Application::getInstance()->storagePath(...$parts);
    }
}

// Routing ---------------------------------------------------------------------

if (!function_exists('path')) {
    /**
     * A route: `path('posts/<int:id>/', [PostController::class, 'detail'], name: 'post-detail')`.
     *
     * Give it an `include_urls()` or `group()` instead of a view to mount
     * those routes under the pattern.
     *
     * @param list<string> $methods allowed HTTP methods (all when empty)
     * @param list<mixed> $middleware
     */
    function path(string $route, mixed $view, ?string $name = null, array $methods = [], array $middleware = []): Route|RouteGroup
    {
        if ($view instanceof RouteGroup) {
            return $view->mount($route, false, $middleware);
        }

        return Route::path($route, $view, $name, $methods, $middleware);
    }
}

if (!function_exists('re_path')) {
    /**
     * A route with a regular expression: `re_path('^archive/(?P<year>[0-9]{4})/$', ...)`.
     * Named groups become view arguments (as strings).
     *
     * @param list<string> $methods
     * @param list<mixed> $middleware
     */
    function re_path(string $route, mixed $view, ?string $name = null, array $methods = [], array $middleware = []): Route|RouteGroup
    {
        if ($view instanceof RouteGroup) {
            return $view->mount($route, true, $middleware);
        }

        return Route::regex($route, $view, $name, $methods, $middleware);
    }
}

if (!function_exists('include_urls')) {
    /**
     * Routes from another URLconf, to mount with path():
     * `path('blog/', include_urls('apps/blog/urls.php', namespace: 'blog'))`.
     *
     * Django calls this `include()`; that name is a reserved word in PHP.
     *
     * @param string|array<mixed> $urlconf a file (relative to the project) or a list of routes
     */
    function include_urls(string|array $urlconf, ?string $namespace = null): RouteGroup
    {
        if (is_string($urlconf)) {
            $file = Application::hasInstance() ? Application::getInstance()->resolvePath($urlconf) : $urlconf;
            if (!is_file($file)) {
                throw new BrewPHP\Core\ImproperlyConfigured(sprintf('Included URLconf "%s" does not exist.', $urlconf));
            }
            $urlconf = (static fn (): mixed => require $file)();
            if (!is_array($urlconf)) {
                throw new BrewPHP\Core\ImproperlyConfigured(sprintf('URLconf "%s" must return an array of routes.', $file));
            }
        }

        return new RouteGroup($urlconf, [], $namespace);
    }
}

if (!function_exists('group')) {
    /**
     * Routes sharing a prefix and middleware:
     * `group('api/', [path(...), path(...)], middleware: [ApiMiddleware::class])`.
     *
     * @param array<mixed> $routes
     * @param list<mixed> $middleware
     */
    function group(string $prefix, array $routes, array $middleware = [], ?string $namespace = null): RouteGroup
    {
        return new RouteGroup($routes, [[ltrim($prefix, '/'), false]], $namespace, $middleware);
    }
}

if (!function_exists('routes_from')) {
    /**
     * Routes declared with `#[Route]` attributes on controller classes.
     *
     * @param class-string ...$classes
     * @return list<Route>
     */
    function routes_from(string ...$classes): array
    {
        return Router::fromAttributes(...$classes);
    }
}

if (!function_exists('reverse')) {
    /**
     * The path of a named route: `reverse('blog:post-detail', ['id' => 5])` gives "/blog/posts/5/".
     *
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $query
     */
    function reverse(string $name, array $parameters = [], array $query = []): string
    {
        return Application::getInstance()->router()->reverse($name, $parameters, $query);
    }
}

if (!function_exists('url')) {
    /**
     * An absolute URL for a path on this site: `url(reverse('home'))`
     * gives "https://example.com/". Uses the current request's scheme and
     * host, or the APP_URL setting outside a request.
     */
    function url(string $path = '/'): string
    {
        $app = Application::getInstance();
        if ($app->has(Request::class)) {
            return $app->get(Request::class)->absoluteUrl($path);
        }
        $base = rtrim($app->settings()->string('APP_URL', 'http://localhost'), '/');

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) ? $path : $base . '/' . ltrim($path, '/');
    }
}

// Responses -------------------------------------------------------------------

if (!function_exists('response')) {
    /**
     * @param array<string, string> $headers
     */
    function response(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }
}

if (!function_exists('json')) {
    /**
     * @param array<string, string> $headers
     */
    function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to a URL or a named route: `redirect('/login/')`,
     * `redirect('post-detail', ['id' => 5])`.
     *
     * @param array<string, mixed> $parameters route parameters when $to is a name
     */
    function redirect(string $to, array $parameters = [], int $status = 302): RedirectResponse
    {
        $isUrl = str_contains($to, '/') || str_starts_with($to, '?') || str_starts_with($to, '#');

        return new RedirectResponse($isUrl ? $to : reverse($to, $parameters), $status);
    }
}

if (!function_exists('render')) {
    /**
     * Render a template into a response: `render('blog/post.php', ['post' => $post])`.
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    function render(string $template, array $context = [], int $status = 200, array $headers = []): Response
    {
        return new Response(Application::getInstance()->templates()->render($template, $context), $status, $headers);
    }
}

if (!function_exists('abort')) {
    /**
     * Stop and answer with an error page: `abort(404)`, `abort(403, 'Members only')`.
     */
    function abort(int $status, string $message = ''): never
    {
        throw match ($status) {
            400 => $message !== '' ? new BadRequest($message) : new BadRequest(),
            403 => $message !== '' ? new PermissionDenied($message) : new PermissionDenied(),
            404 => $message !== '' ? new Http404($message) : new Http404(),
            default => new HttpException($status, $message),
        };
    }
}

// Output ----------------------------------------------------------------------

if (!function_exists('e')) {
    /**
     * Escape a value for HTML. Use it for everything you print in a PHP template.
     */
    function e(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if ($value === null || is_bool($value)) {
            return $value ? '1' : '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException(sprintf('Cannot print a value of type %s.', get_debug_type($value)));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

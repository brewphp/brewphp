<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Http\BadRequest;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Pipeline;
use BrewPHP\Routing\RouteNotFound;
use BrewPHP\Views\ViewResolver;

/**
 * Turns a Request into a Response:
 *
 * 1. check the Host header against ALLOWED_HOSTS;
 * 2. run the global MIDDLEWARE;
 * 3. resolve the route, run its middleware and call its view;
 * 4. turn any exception into an error page.
 *
 * Exceptions raised by the view become responses *inside* the middleware
 * stack, so middleware (security headers, sessions...) still sees them.
 */
final class Kernel
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request): Response
    {
        $this->app->instance(Request::class, $request);
        $errors = new ErrorHandler($this->app);

        try {
            $this->validateHost($request);
            $middleware = array_values($this->app->settings()->array('MIDDLEWARE'));

            return (new Pipeline($this->app, $middleware))->handle($request, function (Request $request) use ($errors): Response {
                try {
                    return $this->dispatch($request);
                } catch (\Throwable $e) {
                    return $errors->render($e, $request);
                }
            });
        } catch (\Throwable $e) {
            return $errors->render($e, $request);
        }
    }

    private function dispatch(Request $request): Response
    {
        $router = $this->app->router();

        try {
            $match = $router->resolve($request->method(), $request->path());
        } catch (RouteNotFound $e) {
            return $this->notFound($request, $e);
        }

        $request->setAttribute('route', $match);
        $resolver = new ViewResolver($this->app);

        return (new Pipeline($this->app, $match->route->middleware))->handle(
            $request,
            static fn (Request $request): Response => $resolver->call($match->route->view, $request, $match->parameters),
        );
    }

    /**
     * Before giving up with a 404: redirect "/about" to "/about/" when only
     * the latter exists (APPEND_SLASH), or show the welcome page when the
     * project has no routes yet and DEBUG is on.
     */
    private function notFound(Request $request, RouteNotFound $e): Response
    {
        $path = $request->path();
        $router = $this->app->router();

        if (
            $this->app->settings()->bool('APPEND_SLASH')
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && !str_ends_with($path, '/')
            && $router->matches($path . '/')
        ) {
            $query = (string) $request->server('QUERY_STRING', '');
            $rawPath = (string) (parse_url((string) $request->server('REQUEST_URI', '/'), PHP_URL_PATH) ?: '/');
            // A single leading slash: "//evil.example" must not become an off-site redirect.
            $target = '/' . ltrim($rawPath, '/\\') . '/';

            return new RedirectResponse($target . ($query !== '' ? '?' . $query : ''), 301);
        }

        if ($path === '/' && $router->routes() === [] && $this->app->isDebug()) {
            return new Response($this->app->templates()->render('brewphp/welcome.php', [
                'version' => Application::VERSION,
                'phpVersion' => PHP_VERSION,
            ]));
        }

        throw $e;
    }

    /**
     * Reject requests for hosts the site does not serve (protects against
     * Host header attacks). With DEBUG on and no ALLOWED_HOSTS, localhost
     * addresses are allowed.
     */
    private function validateHost(Request $request): void
    {
        $host = $request->host();
        $allowed = array_filter($this->app->settings()->array('ALLOWED_HOSTS'), 'is_string');
        if ($allowed === [] && $this->app->isDebug()) {
            $allowed = ['.localhost', 'localhost', '127.0.0.1', '[::1]'];
        }

        if (!self::isValidHost($host)) {
            throw new BadRequest(sprintf('Invalid Host header "%s".', $host));
        }
        foreach ($allowed as $pattern) {
            $pattern = strtolower($pattern);
            if (
                $pattern === '*'
                || $pattern === $host
                || (str_starts_with($pattern, '.') && (str_ends_with($host, $pattern) || $host === substr($pattern, 1)))
            ) {
                return;
            }
        }

        throw new BadRequest(sprintf('Host "%s" is not in ALLOWED_HOSTS. Add it to the setting to serve this site.', $host));
    }

    private static function isValidHost(string $host): bool
    {
        return preg_match('/^([a-z0-9.-]+|\[[a-f0-9:.]+\])$/', $host) === 1;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Views;

use BrewPHP\Core\Application;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Security\Redirects;
use BrewPHP\Views\View;

/**
 * Shared helpers of the authentication views.
 */
abstract class AuthView extends View
{
    /** The template to render. */
    public string $templateName = '';

    /** @var array<string, mixed> extra template variables */
    public array $extraContext = [];

    /**
     * Render the template with `$context`; never cached by browsers or proxies.
     *
     * @param array<string, mixed> $context
     */
    protected function page(Request $request, array $context, int $status = 200): Response
    {
        $response = render($this->templateName, [...$this->extraContext, ...$context], $status);

        return self::noStore($response);
    }

    /**
     * The `next` URL from the form or query string, when it is safe (on
     * this site), else `$default`. A path or a route name is accepted as
     * the default.
     */
    protected function nextUrl(Request $request, ?string $default): ?string
    {
        $next = $request->post('next') ?? $request->query('next');
        if (Redirects::isSafe($next, $request)) {
            return (string) $next;
        }
        if ($default === null || $default === '') {
            return null;
        }

        return str_starts_with($default, '/') || preg_match('#^https?://#', $default) === 1 ? $default : reverse($default);
    }

    protected function redirectTo(string $url): RedirectResponse
    {
        $response = new RedirectResponse($url);
        self::noStore($response);

        return $response;
    }

    protected function setting(string $key, ?string $default = null): ?string
    {
        $value = Application::getInstance()->settings()->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private static function noStore(Response $response): Response
    {
        return $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
    }
}

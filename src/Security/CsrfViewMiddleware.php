<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Core\Application;
use BrewPHP\Http\Cookie;
use BrewPHP\Http\HttpException;
use BrewPHP\Http\PermissionDenied;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use BrewPHP\Routing\RouteNotFound;
use Closure;

/**
 * Protects every POST, PUT, PATCH and DELETE request against cross-site
 * request forgery, like Django's CsrfViewMiddleware:
 *
 * 1. the Origin header (or, over HTTPS without one, the Referer) must be
 *    this site or listed in CSRF_TRUSTED_ORIGINS;
 * 2. the request must send a token, in the `csrfmiddlewaretoken` form field
 *    or the X-CSRFToken header, that matches the secret in the cookie.
 *
 * Failures are 403 responses. Views marked #[CsrfExempt] are skipped.
 * Put it after SessionMiddleware when CSRF_USE_SESSIONS is on.
 */
final class CsrfViewMiddleware implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $secret = $this->readSecret($request);
        $request->setAttribute(Csrf::SECRET_ATTRIBUTE, $secret);

        if (!in_array($request->method(), self::SAFE_METHODS, true) && !$this->isExempt($request)) {
            $this->check($request, $secret);
        }

        $response = $next($request);

        if ($request->attribute(Csrf::USED_ATTRIBUTE) === true) {
            $current = $request->attribute(Csrf::SECRET_ATTRIBUTE);
            if (is_string($current)) {
                $this->storeSecret($request, $response, $current);
            }
            $response->vary('Cookie');
        }

        return $response;
    }

    private function check(Request $request, ?string $secret): void
    {
        $origin = $request->header('origin');
        if ($origin !== null) {
            if (!$this->isTrustedOrigin($origin, $request)) {
                $this->reject(sprintf('Origin checking failed - %s does not match any trusted origins.', $origin));
            }
        } elseif ($request->isSecure()) {
            // Without Origin, a secure request must come from a page of this site.
            $referer = $request->header('referer');
            if ($referer === null || $referer === '') {
                $this->reject('Referer checking failed - no Referer.');
            }
            $parts = parse_url($referer);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                $this->reject('Referer checking failed - Referer is malformed.');
            }
            if (strtolower($parts['scheme']) !== 'https') {
                $this->reject('Referer checking failed - Referer is insecure while host is secure.');
            }
            $refererOrigin = 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (!$this->isTrustedOrigin($refererOrigin, $request)) {
                $this->reject(sprintf('Referer checking failed - %s does not match any trusted origins.', $referer));
            }
        }

        if ($secret === null) {
            $this->reject($this->settings()->bool('CSRF_USE_SESSIONS') ? 'CSRF token from the session is missing.' : 'CSRF cookie not set.');
        }
        $token = $request->post(Csrf::FIELD);
        if (!is_string($token) || $token === '') {
            $token = $request->header($this->settings()->string('CSRF_HEADER_NAME', 'X-CSRFToken')) ?? '';
        }
        if ($token === '') {
            $this->reject('CSRF token missing.');
        }
        if (!Csrf::matches($token, $secret)) {
            $this->reject('CSRF token incorrect.');
        }
    }

    /**
     * The request's own origin, or an entry of CSRF_TRUSTED_ORIGINS, such
     * as "https://example.com" or "https://*.example.com".
     */
    private function isTrustedOrigin(string $origin, Request $request): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        if ($origin === strtolower($request->scheme() . '://' . $request->httpHost())) {
            return true;
        }
        foreach ($this->settings()->array('CSRF_TRUSTED_ORIGINS') as $trusted) {
            if (!is_string($trusted)) {
                continue;
            }
            $trusted = strtolower(rtrim($trusted, '/'));
            if ($trusted === $origin) {
                return true;
            }
            if (preg_match('#^(https?)://\*\.(.+)$#', $trusted, $match) === 1) {
                $parts = parse_url($origin);
                if ($parts !== false && ($parts['scheme'] ?? '') === $match[1] && str_ends_with('.' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), '.' . $match[2])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Views that cannot run (no route) need no protection: the 404 or 405
     * is produced as usual.
     */
    private function isExempt(Request $request): bool
    {
        try {
            $match = $this->app->router()->resolve($request->method(), $request->path());
        } catch (RouteNotFound|HttpException) {
            return true;
        }

        return CsrfExempt::appliesTo($match->route->view);
    }

    private function readSecret(Request $request): ?string
    {
        $secret = $this->settings()->bool('CSRF_USE_SESSIONS')
            ? $request->session()->get('_csrf_secret')
            : $request->cookie($this->settings()->string('CSRF_COOKIE_NAME', 'csrftoken'));

        return is_string($secret) && Csrf::isValidSecret($secret) ? $secret : null;
    }

    private function storeSecret(Request $request, Response $response, string $secret): void
    {
        if ($this->settings()->bool('CSRF_USE_SESSIONS')) {
            $request->session()->set('_csrf_secret', $secret);

            return;
        }
        $settings = $this->settings();
        $sameSite = $settings->get('CSRF_COOKIE_SAMESITE', 'Lax');
        $domain = $settings->get('CSRF_COOKIE_DOMAIN');
        $response->cookie(new Cookie(
            $settings->string('CSRF_COOKIE_NAME', 'csrftoken'),
            $secret,
            time() + (int) $settings->get('CSRF_COOKIE_AGE', 31449600),
            $settings->string('CSRF_COOKIE_PATH', '/'),
            is_string($domain) ? $domain : null,
            $settings->bool('CSRF_COOKIE_SECURE'),
            $settings->bool('CSRF_COOKIE_HTTPONLY'),
            in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax',
        ));
    }

    private function reject(string $reason): never
    {
        throw new PermissionDenied($this->app->isDebug()
            ? 'CSRF verification failed. ' . $reason
            : 'CSRF verification failed. Request aborted.');
    }

    private function settings(): \BrewPHP\Core\Settings
    {
        return $this->app->settings();
    }
}

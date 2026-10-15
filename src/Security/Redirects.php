<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Http\Request;

/**
 * Checks for redirect targets that come from the user, such as the
 * `?next=` parameter of a login page. Redirecting to any URL a visitor
 * gives is an "open redirect": a phishing link can send people to your
 * login page and on to a look-alike site.
 *
 *     $next = $request->query('next');
 *     return redirect(Redirects::isSafe($next, $request) ? $next : '/');
 */
final class Redirects
{
    /**
     * Whether `$url` stays on this site (or on one of `$allowedHosts`):
     * a relative path, or an http(s) URL whose host (with port) is the
     * request's. A page served over HTTPS only redirects to HTTPS.
     *
     * @param list<string> $allowedHosts extra "host" or "host:port" values
     */
    public static function isSafe(mixed $url, Request $request, array $allowedHosts = []): bool
    {
        if (!is_string($url)) {
            return false;
        }
        // Callers redirect to the value as given, so it is checked as given:
        // no surrounding spaces, control characters (CR/LF would break the
        // Location header) or backslashes (browsers read "/\evil.com" as "//evil.com").
        if ($url === '' || trim($url) !== $url || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1 || str_starts_with($url, '///')) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        if ($scheme === null && $host === null) {
            // A path: "/next" or "next/", but never "javascript:..." (that has a scheme).
            return !str_starts_with($url, '//');
        }
        if ($scheme !== null && !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if ($host === null || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if ($request->isSecure() && $scheme !== 'https' && $scheme !== null) {
            return false;
        }
        $netloc = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $allowed = array_map('strtolower', [$request->httpHost(), ...$allowedHosts]);

        return in_array($netloc, $allowed, true);
    }
}

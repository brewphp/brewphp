<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\Cookie;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Middleware\Middleware;
use Closure;

/**
 * Gives every request a session (`$request->session()`), loaded on first
 * use, and saves it when it changed.
 *
 * The cookie is HttpOnly and SameSite=Lax; set SESSION_COOKIE_SECURE in
 * production (HTTPS). Settings: SESSION_ENGINE ('file', 'database' or a
 * SessionStore class), SESSION_COOKIE_NAME, SESSION_COOKIE_AGE,
 * SESSION_COOKIE_SECURE, SESSION_COOKIE_HTTPONLY, SESSION_COOKIE_SAMESITE,
 * SESSION_COOKIE_DOMAIN, SESSION_COOKIE_PATH, SESSION_EXPIRE_AT_BROWSER_CLOSE,
 * SESSION_SAVE_EVERY_REQUEST and SESSION_GC_PROBABILITY (percent).
 */
final class SessionMiddleware implements Middleware
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $settings = $this->app->settings();
        $name = $settings->string('SESSION_COOKIE_NAME', 'brewphp_session');
        $cookie = $request->cookie($name);
        $store = self::store($this->app);
        $session = new Session($store, $cookie, (int) $settings->get('SESSION_COOKIE_AGE', 1209600));
        $request->setAttribute('session', $session);

        $response = $next($request);

        if (random_int(1, 100) <= (int) $settings->get('SESSION_GC_PROBABILITY', 1)) {
            $store->gc();
        }
        if (!$session->wasAccessed() && !$session->isModified()) {
            return $response;
        }
        $response->vary('Cookie');

        if ($session->isEmpty()) {
            // Nothing to keep: drop the stored session and the cookie.
            if ($session->isModified() || $session->id() !== null) {
                $session->destroy();
            }
            if ($cookie !== null) {
                $response->cookie(Cookie::forget($name, $settings->string('SESSION_COOKIE_PATH', '/'), $this->domain()));
            }

            return $response;
        }

        if (($session->isModified() || $settings->bool('SESSION_SAVE_EVERY_REQUEST')) && $response->status() < 500) {
            $id = $session->save();
            $sameSite = $settings->get('SESSION_COOKIE_SAMESITE', 'Lax');
            $response->cookie(new Cookie(
                $name,
                $id,
                $settings->bool('SESSION_EXPIRE_AT_BROWSER_CLOSE') ? 0 : time() + $session->lifetime(),
                $settings->string('SESSION_COOKIE_PATH', '/'),
                $this->domain(),
                $settings->bool('SESSION_COOKIE_SECURE'),
                $settings->bool('SESSION_COOKIE_HTTPONLY', true),
                in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax',
            ));
        }

        return $response;
    }

    /**
     * The store chosen by SESSION_ENGINE.
     */
    public static function store(Application $app): SessionStore
    {
        $engine = $app->settings()->get('SESSION_ENGINE', 'file');

        return match (true) {
            $engine === 'file' => new FileSessionStore($app->storagePath('sessions')),
            $engine === 'database' => new DatabaseSessionStore($app->db()),
            $engine === 'array' => new ArraySessionStore(),
            is_string($engine) && is_subclass_of($engine, SessionStore::class) => $app->make($engine),
            default => throw new ImproperlyConfigured(sprintf(
                'SESSION_ENGINE must be "file", "database", "array" or a %s class, got %s.',
                SessionStore::class,
                is_string($engine) ? '"' . $engine . '"' : get_debug_type($engine),
            )),
        };
    }

    private function domain(): ?string
    {
        $domain = $this->app->settings()->get('SESSION_COOKIE_DOMAIN');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }
}

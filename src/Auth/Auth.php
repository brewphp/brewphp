<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

use BrewPHP\Auth\Backends\AuthBackend;
use BrewPHP\Auth\Backends\ModelBackend;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Http\Request;
use BrewPHP\Security\Csrf;

/**
 * Logging users in and out, like `django.contrib.auth`:
 *
 *     $user = Auth::authenticate($request, ['username' => $u, 'password' => $p]);
 *     if ($user !== null) {
 *         Auth::login($request, $user);
 *     }
 *     Auth::logout($request);
 *
 * Login stores the user id, the backend and a hash of the password hash in
 * the session; a password change makes old sessions invalid. The session
 * id and the CSRF token change at login, so values an attacker planted in
 * a visitor's browser before login are useless afterwards.
 */
final class Auth
{
    public const SESSION_KEY = '_auth_user_id';
    public const BACKEND_SESSION_KEY = '_auth_user_backend';
    public const HASH_SESSION_KEY = '_auth_user_hash';

    /**
     * Try each backend of AUTHENTICATION_BACKENDS; the first user found wins.
     *
     * @param array<string, mixed> $credentials
     */
    public static function authenticate(?Request $request, array $credentials): ?User
    {
        foreach (self::backends() as $backend) {
            $user = $backend->authenticate($request, $credentials);
            if ($user !== null) {
                $user->authBackend = $backend::class;

                return $user;
            }
        }

        return null;
    }

    /**
     * Log a user in for this session.
     */
    public static function login(Request $request, User $user, ?string $backend = null): void
    {
        $session = $request->session();
        $backend ??= $user->authBackend ?? (self::backends() !== [] ? self::backends()[0]::class : ModelBackend::class);
        $current = $session->get(self::SESSION_KEY);
        $hash = $user->sessionAuthHash();
        if ($current !== null && ((string) $current !== (string) $user->pk || !hash_equals((string) $session->get(self::HASH_SESSION_KEY, ''), $hash))) {
            // Another user (or a stale login) in this session: start clean.
            $session->flush();
        } else {
            $session->cycleKey();
        }
        $session->set(self::SESSION_KEY, $user->pk);
        $session->set(self::BACKEND_SESSION_KEY, $backend);
        $session->set(self::HASH_SESSION_KEY, $hash);
        Csrf::rotate($request);
        $request->setUser($user);

        $now = new \DateTimeImmutable();
        User::objects()->filter(pk: $user->pk)->update(['last_login' => $now]);
        $user->setAttribute('last_login', $now);
    }

    /**
     * Log out: the session is emptied and gets a new id.
     */
    public static function logout(Request $request): void
    {
        $request->session()->flush();
        $request->setUser(new AnonymousUser());
    }

    /**
     * The user of the request's session, or an AnonymousUser. Used by
     * AuthenticationMiddleware.
     */
    public static function user(Request $request): Authenticatable
    {
        $session = $request->session();
        $id = $session->get(self::SESSION_KEY);
        $backendClass = $session->get(self::BACKEND_SESSION_KEY);
        if ((!is_int($id) && !is_string($id)) || !is_string($backendClass)) {
            return new AnonymousUser();
        }
        // Only a configured backend, never a class named by stored data.
        $backend = null;
        foreach (self::backends() as $candidate) {
            if ($candidate::class === $backendClass) {
                $backend = $candidate;
            }
        }
        $user = $backend?->getUser($id);
        if ($user === null) {
            return new AnonymousUser();
        }
        $stored = $session->get(self::HASH_SESSION_KEY);
        if (!is_string($stored) || !hash_equals($user->sessionAuthHash(), $stored)) {
            // The password changed since this login.
            $session->flush();

            return new AnonymousUser();
        }
        $user->authBackend = $backendClass;

        return $user;
    }

    /**
     * Keep the current session logged in after the user changed their own
     * password (other sessions are logged out).
     */
    public static function updateSessionAuthHash(Request $request, User $user): void
    {
        $session = $request->session();
        $session->cycleKey();
        if ((string) $session->get(self::SESSION_KEY) === (string) $user->pk) {
            $session->set(self::HASH_SESSION_KEY, $user->sessionAuthHash());
        }
    }

    /**
     * @return list<AuthBackend>
     */
    public static function backends(): array
    {
        $entries = Application::hasInstance() ? Application::getInstance()->settings()->array('AUTHENTICATION_BACKENDS') : [];
        if ($entries === []) {
            $entries = [ModelBackend::class];
        }
        $backends = [];
        foreach ($entries as $entry) {
            if (is_string($entry) && is_subclass_of($entry, AuthBackend::class)) {
                $entry = Application::hasInstance() ? Application::getInstance()->make($entry) : new $entry();
            }
            if (!$entry instanceof AuthBackend) {
                throw new ImproperlyConfigured(sprintf('AUTHENTICATION_BACKENDS entries must be %s class names.', AuthBackend::class));
            }
            $backends[] = $entry;
        }

        return $backends;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Backends;

use BrewPHP\Auth\Models\User;
use BrewPHP\Http\Request;

/**
 * Checks credentials and loads users, like Django's authentication
 * backends. List them in AUTHENTICATION_BACKENDS.
 */
interface AuthBackend
{
    /**
     * The user these credentials belong to, or null.
     *
     * @param array<string, mixed> $credentials e.g. ['username' => ..., 'password' => ...]
     */
    public function authenticate(?Request $request, array $credentials): ?User;

    /**
     * A user by primary key (to restore a login from the session), or null.
     */
    public function getUser(int|string $id): ?User;
}

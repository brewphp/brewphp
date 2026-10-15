<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Backends;

use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Http\Request;

/**
 * Username and password against the `auth_user` table. Inactive users
 * cannot log in. A wrong username takes as long as a wrong password, so
 * response times do not reveal which usernames exist.
 */
final class ModelBackend implements AuthBackend
{
    public function authenticate(?Request $request, array $credentials): ?User
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
            return null;
        }
        $user = User::objects()->filter(username: $username)->first();
        if (!$user instanceof User) {
            Passwords::runDummyHash($password);

            return null;
        }

        return $user->checkPassword($password) && $user->isActive() ? $user : null;
    }

    public function getUser(int|string $id): ?User
    {
        $user = User::objects()->filter(pk: $id)->first();

        return $user instanceof User && $user->isActive() ? $user : null;
    }
}

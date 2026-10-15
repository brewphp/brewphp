<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

/**
 * The permissions of one app in templates (see PermWrapper). It counts as
 * true when the user has any permission in the app.
 */
final class AppPerms implements \Countable
{
    public function __construct(private readonly Authenticatable $user, private readonly string $appLabel)
    {
    }

    public function __get(string $codename): bool
    {
        return $this->user->hasPerm($this->appLabel . '.' . $codename);
    }

    public function __isset(string $codename): bool
    {
        return true;
    }

    public function count(): int
    {
        return $this->user->hasModulePerms($this->appLabel) ? 1 : 0;
    }
}

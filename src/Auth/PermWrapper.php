<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

/**
 * The `perms` template variable: `{% if perms.blog %}` (any permission in
 * the app) and `{% if perms.blog.add_post %}` (one permission).
 */
final class PermWrapper
{
    public function __construct(private readonly Authenticatable $user)
    {
    }

    public function __get(string $appLabel): AppPerms
    {
        return new AppPerms($this->user, $appLabel);
    }

    public function __isset(string $appLabel): bool
    {
        return true;
    }
}

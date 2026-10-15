<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

/**
 * A visitor who is not logged in. Has no permissions.
 */
final class AnonymousUser implements Authenticatable, \Stringable
{
    public ?int $id = null;

    public ?int $pk = null;

    public string $username = '';

    public function isAuthenticated(): bool
    {
        return false;
    }

    public function isAnonymous(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function isStaff(): bool
    {
        return false;
    }

    public function isSuperuser(): bool
    {
        return false;
    }

    public function getUsername(): string
    {
        return '';
    }

    public function hasPerm(string $permission): bool
    {
        return false;
    }

    public function hasPerms(array $permissions): bool
    {
        return $permissions === [];
    }

    public function hasModulePerms(string $appLabel): bool
    {
        return false;
    }

    public function getAllPermissions(): array
    {
        return [];
    }

    public function __toString(): string
    {
        return 'AnonymousUser';
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

/**
 * What `$request->user()` returns: a User, or an AnonymousUser for visitors
 * who are not logged in. Templates read them the same way:
 * `{% if user.is_authenticated %}`, `{{ user.username }}`.
 */
interface Authenticatable
{
    public function isAuthenticated(): bool;

    public function isAnonymous(): bool;

    public function isActive(): bool;

    public function isStaff(): bool;

    public function isSuperuser(): bool;

    public function getUsername(): string;

    /**
     * Whether the user has a permission, e.g. "blog.add_post".
     */
    public function hasPerm(string $permission): bool;

    /**
     * @param list<string> $permissions
     */
    public function hasPerms(array $permissions): bool;

    /**
     * Whether the user has any permission in an app, e.g. "blog".
     */
    public function hasModulePerms(string $appLabel): bool;

    /**
     * Every permission of the user, as "app_label.codename" strings.
     *
     * @return list<string>
     */
    public function getAllPermissions(): array;
}

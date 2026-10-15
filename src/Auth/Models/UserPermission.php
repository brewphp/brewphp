<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Index;
use BrewPHP\Db\Model;
use BrewPHP\Db\OnDelete;

/**
 * A permission given to one user (table `auth_user_permissions`).
 */
final class UserPermission extends Model
{
    public static function fields(): array
    {
        return [
            'user' => new ForeignKey(User::class, onDelete: OnDelete::Cascade, relatedName: 'permission_links'),
            'permission' => new ForeignKey(Permission::class, onDelete: OnDelete::Cascade, relatedName: 'user_links'),
        ];
    }

    public static function table(): string
    {
        return 'auth_user_permissions';
    }

    public static function indexes(): array
    {
        return [new Index(['user', 'permission'], unique: true)];
    }
}

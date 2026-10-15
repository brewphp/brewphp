<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Index;
use BrewPHP\Db\Model;
use BrewPHP\Db\OnDelete;

/**
 * A permission of a group (table `auth_group_permissions`).
 */
final class GroupPermission extends Model
{
    public static function fields(): array
    {
        return [
            'group' => new ForeignKey(Group::class, onDelete: OnDelete::Cascade, relatedName: 'permission_links'),
            'permission' => new ForeignKey(Permission::class, onDelete: OnDelete::Cascade, relatedName: 'group_links'),
        ];
    }

    public static function table(): string
    {
        return 'auth_group_permissions';
    }

    public static function indexes(): array
    {
        return [new Index(['group', 'permission'], unique: true)];
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Index;
use BrewPHP\Db\Model;
use BrewPHP\Db\OnDelete;

/**
 * Membership of a user in a group (table `auth_user_groups`).
 */
final class UserGroup extends Model
{
    public static function fields(): array
    {
        return [
            'user' => new ForeignKey(User::class, onDelete: OnDelete::Cascade, relatedName: 'group_links'),
            'group' => new ForeignKey(Group::class, onDelete: OnDelete::Cascade, relatedName: 'user_links'),
        ];
    }

    public static function table(): string
    {
        return 'auth_user_groups';
    }

    public static function indexes(): array
    {
        return [new Index(['user', 'group'], unique: true)];
    }
}

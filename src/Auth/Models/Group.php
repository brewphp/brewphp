<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Core\AltersData;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Model;
use BrewPHP\Db\QuerySet;

/**
 * A group of users that share permissions (table `auth_group`).
 *
 *     $editors = Group::create(['name' => 'Editors']);
 *     $editors->grant('blog.add_post', 'blog.change_post');
 *     $ann->addToGroup($editors);
 *
 * @property string $name
 */
class Group extends Model
{
    public static function fields(): array
    {
        return [
            'name' => new CharField(maxLength: 150, unique: true),
        ];
    }

    public static function ordering(): array
    {
        return ['name'];
    }

    /**
     * @return QuerySet<Permission>
     */
    public function permissions(): QuerySet
    {
        return Permission::objects()->filter(group_links__group: $this->pk);
    }

    /**
     * @return QuerySet<User>
     */
    public function users(): QuerySet
    {
        return User::objects()->filter(group_links__group: $this->pk);
    }

    #[AltersData]
    public function grant(Permission|string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            GroupPermission::objects()->getOrCreate(['group' => $this, 'permission' => Permission::resolve($permission)]);
        }
    }

    #[AltersData]
    public function revoke(Permission|string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            GroupPermission::objects()->filter(group: $this->pk, permission: Permission::resolve($permission)->pk)->delete();
        }
    }

    /**
     * Make the group's permissions exactly these.
     *
     * @param iterable<Permission> $permissions
     */
    #[AltersData]
    public function setPermissions(iterable $permissions): void
    {
        $ids = [];
        foreach ($permissions as $permission) {
            $ids[] = $permission->pk;
            GroupPermission::objects()->getOrCreate(['group' => $this, 'permission' => $permission]);
        }
        $stale = GroupPermission::objects()->filter(group: $this->pk);
        if ($ids !== []) {
            $stale = $stale->exclude(permission__in: $ids);
        }
        $stale->delete();
    }

    public function __toString(): string
    {
        return (string) $this->getAttribute('name');
    }
}

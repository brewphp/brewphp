<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Index;
use BrewPHP\Db\Model;
use BrewPHP\Db\ObjectDoesNotExist;

/**
 * A permission such as "blog.add_post" (table `auth_permission`).
 *
 * `migrate` creates add, change, delete and view permissions for every
 * model of the installed apps. Add your own with
 * `Permission::create(['name' => 'Can publish posts', 'app_label' => 'blog', 'codename' => 'publish_post'])`.
 *
 * @property string $name
 * @property string $app_label
 * @property string $codename
 */
class Permission extends Model
{
    public static function fields(): array
    {
        return [
            'name' => new CharField(maxLength: 255),
            'app_label' => new CharField(maxLength: 100),
            'codename' => new CharField(maxLength: 100),
        ];
    }

    public static function indexes(): array
    {
        return [new Index(['app_label', 'codename'], unique: true)];
    }

    public static function ordering(): array
    {
        return ['app_label', 'codename'];
    }

    /**
     * A permission by "app_label.codename" (an object is returned as is).
     */
    public static function resolve(self|string $permission): self
    {
        if ($permission instanceof self) {
            return $permission;
        }
        [$app, $codename] = array_pad(explode('.', $permission, 2), 2, '');
        $found = static::objects()->filter(app_label: $app, codename: $codename)->first();

        return $found instanceof self ? $found : throw new ObjectDoesNotExist(sprintf('No permission "%s". Did you run migrate?', $permission));
    }

    /**
     * "blog.add_post".
     */
    public function key(): string
    {
        return $this->getAttribute('app_label') . '.' . $this->getAttribute('codename');
    }

    public function __toString(): string
    {
        return $this->getAttribute('app_label') . ' | ' . $this->getAttribute('name');
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Forms;

use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\Permission;
use BrewPHP\Core\AltersData;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Fields\ModelMultipleChoiceField;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Forms\Widgets\SelectMultiple;

/**
 * The admin form of a group; its permissions are only editable by superusers.
 */
final class GroupForm extends ModelForm
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $initial
     */
    public function __construct(
        ?array $data = null,
        array $files = [],
        array $initial = [],
        string $prefix = '',
        ?string $autoId = 'id_%s',
        ?Model $instance = null,
        private readonly bool $withPermissions = false,
    ) {
        if ($withPermissions && $instance instanceof Group && $instance->isPersisted()) {
            $initial += ['permissions' => $instance->permissions()->valuesList('id', flat: true)];
        }
        parent::__construct($data, $files, $initial, $prefix, $autoId, $instance);
    }

    protected function model(): string
    {
        return Group::class;
    }

    protected function fieldNames(): array
    {
        return ['name'];
    }

    public function fields(): array
    {
        $fields = parent::fields();
        if ($this->withPermissions) {
            $fields['permissions'] = new ModelMultipleChoiceField(Permission::objects(), required: false, widget: new SelectMultiple(['size' => 15]));
        }

        return $fields;
    }

    #[AltersData]
    public function save(bool $commit = true): Model
    {
        $group = parent::save($commit);
        if ($commit && $this->withPermissions && $group instanceof Group) {
            $permissions = $this->cleanedData()['permissions'] ?? [];
            $group->setPermissions(is_array($permissions) ? $permissions : []);
        }

        return $group;
    }
}

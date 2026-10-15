<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Forms;

use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\Permission;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\AltersData;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Fields\ModelMultipleChoiceField;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Forms\Widgets\SelectMultiple;

/**
 * The admin form of a user. With `$withPermissions` (only for superusers)
 * it also edits the superuser status, the groups and the permissions:
 * other staff users cannot give anyone (themselves included) more rights.
 */
final class UserChangeForm extends ModelForm
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
        if ($withPermissions && $instance instanceof User && $instance->isPersisted()) {
            $initial += [
                'groups' => $instance->groups()->valuesList('id', flat: true),
                'user_permissions' => $instance->userPermissions()->valuesList('id', flat: true),
            ];
        }
        parent::__construct($data, $files, $initial, $prefix, $autoId, $instance);
    }

    protected function model(): string
    {
        return User::class;
    }

    protected function fieldNames(): array
    {
        $names = ['username', 'first_name', 'last_name', 'email', 'is_active', 'is_staff'];

        return $this->withPermissions ? [...$names, 'is_superuser'] : $names;
    }

    public function fields(): array
    {
        $fields = parent::fields();
        if ($this->withPermissions) {
            $fields['groups'] = new ModelMultipleChoiceField(Group::objects(), required: false, widget: new SelectMultiple(['size' => 6]), helpText: 'The user gets every permission of these groups.');
            $fields['user_permissions'] = new ModelMultipleChoiceField(Permission::objects(), required: false, label: 'User permissions', widget: new SelectMultiple(['size' => 12]));
        }

        return $fields;
    }

    #[AltersData]
    public function save(bool $commit = true): Model
    {
        $user = parent::save($commit);
        if ($commit && $this->withPermissions && $user instanceof User) {
            $data = $this->cleanedData();
            $user->setGroups(is_array($data['groups'] ?? null) ? $data['groups'] : []);
            $user->setPermissions(is_array($data['user_permissions'] ?? null) ? $data['user_permissions'] : []);
        }

        return $user;
    }
}

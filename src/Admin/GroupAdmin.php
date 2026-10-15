<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Admin\Forms\GroupForm;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Form;
use BrewPHP\Http\Request;

/**
 * The admin of groups. Only superusers edit their permissions.
 */
class GroupAdmin extends ModelAdmin
{
    public array $listDisplay = ['name'];

    public array $searchFields = ['name'];

    public array $ordering = ['name'];

    public function getForm(Request $request, ?Model $object, ?array $data = null, array $files = []): Form
    {
        return new GroupForm($data, $files, instance: $object, withPermissions: $request->user()->isSuperuser());
    }
}

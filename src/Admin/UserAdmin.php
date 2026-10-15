<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Admin\Forms\UserChangeForm;
use BrewPHP\Auth\Forms\UserCreationForm;
use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Db\Model;
use BrewPHP\Db\QuerySet;
use BrewPHP\Forms\Form;
use BrewPHP\Http\Request;

/**
 * The admin of users. Adding asks for a username and a password (twice);
 * passwords are never shown, only how they are stored.
 *
 * Staff users who are not superusers cannot see superuser accounts, nor
 * change anyone's superuser status, groups or permissions.
 */
class UserAdmin extends ModelAdmin
{
    public array $listDisplay = ['username', 'email', 'first_name', 'last_name', 'is_staff', 'is_active'];

    public array $listFilter = ['is_staff', 'is_superuser', 'is_active'];

    public array $searchFields = ['username', 'first_name', 'last_name', 'email'];

    public array $ordering = ['username'];

    public array $readonlyFields = ['password_info', 'last_login', 'date_joined'];

    public array $labels = ['password_info' => 'Password'];

    public function getQueryset(Request $request): QuerySet
    {
        $queryset = parent::getQueryset($request);

        return $request->user()->isSuperuser() ? $queryset : $queryset->filter(is_superuser: false);
    }

    public function getReadonlyFields(Request $request, ?Model $object = null): array
    {
        return $object === null ? [] : $this->readonlyFields;
    }

    public function getForm(Request $request, ?Model $object, ?array $data = null, array $files = []): Form
    {
        if ($object === null) {
            return new UserCreationForm($data, $files);
        }

        return new UserChangeForm($data, $files, instance: $object, withPermissions: $request->user()->isSuperuser());
    }

    /**
     * How the password is stored, never the password or its hash.
     */
    public function passwordInfo(User $user): string
    {
        $hash = (string) $user->getAttribute('password');
        if (!Passwords::isUsable($hash)) {
            return 'No password set.';
        }

        return sprintf('Stored as a %s hash. Raw passwords are not stored; change it with "php manage.php changepassword %s".', explode('$', $hash, 2)[0], $user->getUsername());
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Forms;

use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields\CharField;
use BrewPHP\Forms\Widgets\PasswordInput;

/**
 * Change your own password: the old one, then the new one twice.
 */
class PasswordChangeForm extends SetPasswordForm
{
    public function fields(): array
    {
        return [
            'old_password' => new CharField(
                strip: false,
                label: 'Old password',
                widget: new PasswordInput(['autocomplete' => 'current-password', 'autofocus' => true]),
            ),
            ...parent::fields(),
        ];
    }

    public function cleanOldPassword(string $password): string
    {
        if ($this->user === null || !$this->user->checkPassword($password)) {
            throw new ValidationError('Your old password was entered incorrectly. Please enter it again.', 'password_incorrect');
        }

        return $password;
    }
}

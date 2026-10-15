<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Forms;

use BrewPHP\Auth\Forms\AuthenticationForm;
use BrewPHP\Core\ValidationError;

/**
 * The admin login form: like the site's, but only active staff users may
 * log in. The error does not say whether the account exists.
 */
final class AdminAuthenticationForm extends AuthenticationForm
{
    public function clean(array $data): array
    {
        $data = parent::clean($data);
        $user = $this->getUserUnchecked();
        if ($user !== null && !$user->isStaff()) {
            throw new ValidationError(
                'Please enter the correct username and password for a staff account. Note that both fields may be case-sensitive.',
                'invalid_login',
            );
        }

        return $data;
    }
}

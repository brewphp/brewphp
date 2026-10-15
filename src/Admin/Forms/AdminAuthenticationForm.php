<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Forms;

use BrewPHP\Auth\Forms\AuthenticationForm;
use BrewPHP\Core\ValidationError;

/**
 * The admin login form: like the site's, but only active staff users may
 * log in. A wrong password and a non-staff account get the same message,
 * so the form never confirms that a guessed password is right.
 */
final class AdminAuthenticationForm extends AuthenticationForm
{
    public const ERROR = 'Please enter the correct username and password for a staff account. Note that both fields may be case-sensitive.';

    public function clean(array $data): array
    {
        try {
            $data = parent::clean($data);
        } catch (ValidationError $e) {
            throw $e->errorCode === 'invalid_login' ? new ValidationError(self::ERROR, 'invalid_login') : $e;
        }
        $user = $this->getUserUnchecked();
        if ($user !== null && !$user->isStaff()) {
            throw new ValidationError(self::ERROR, 'invalid_login');
        }

        return $data;
    }
}

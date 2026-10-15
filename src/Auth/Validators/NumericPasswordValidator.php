<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Validators;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Core\ValidationError;

/**
 * The password cannot be only digits.
 */
final class NumericPasswordValidator implements PasswordValidator
{
    public function validate(string $password, ?Authenticatable $user = null): void
    {
        if ($password !== '' && ctype_digit($password)) {
            throw new ValidationError('This password is entirely numeric.', 'password_entirely_numeric');
        }
    }

    public function helpText(): string
    {
        return 'Your password can’t be entirely numeric.';
    }
}

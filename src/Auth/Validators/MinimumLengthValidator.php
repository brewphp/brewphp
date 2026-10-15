<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Validators;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Core\ValidationError;

/**
 * The password must have at least `minLength` characters (8 by default).
 */
final class MinimumLengthValidator implements PasswordValidator
{
    public function __construct(public readonly int $minLength = 8)
    {
    }

    public function validate(string $password, ?Authenticatable $user = null): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($length < $this->minLength) {
            throw new ValidationError(sprintf('This password is too short. It must contain at least %d characters.', $this->minLength), 'password_too_short');
        }
    }

    public function helpText(): string
    {
        return sprintf('Your password must contain at least %d characters.', $this->minLength);
    }
}

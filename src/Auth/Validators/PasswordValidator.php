<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Validators;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Core\ValidationError;

/**
 * A rule for new passwords, listed in AUTH_PASSWORD_VALIDATORS.
 */
interface PasswordValidator
{
    /**
     * @throws ValidationError when the password breaks the rule
     */
    public function validate(string $password, ?Authenticatable $user = null): void;

    /**
     * The rule in one sentence, shown next to password fields.
     */
    public function helpText(): string;
}

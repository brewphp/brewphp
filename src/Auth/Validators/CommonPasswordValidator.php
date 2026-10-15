<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Validators;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Core\ValidationError;

/**
 * The password cannot be one of a list of common passwords (compared in
 * lower case). The default list ships with BrewPHP; pass a file with one
 * password per line to use a longer one.
 */
final class CommonPasswordValidator implements PasswordValidator
{
    /** @var array<string, array<string, true>> */
    private static array $lists = [];

    private readonly string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 3) . '/resources/auth/common-passwords.txt';
    }

    public function validate(string $password, ?Authenticatable $user = null): void
    {
        if (isset($this->passwords()[strtolower(trim($password))])) {
            throw new ValidationError('This password is too common.', 'password_too_common');
        }
    }

    public function helpText(): string
    {
        return 'Your password can’t be a commonly used password.';
    }

    /**
     * @return array<string, true>
     */
    private function passwords(): array
    {
        if (!isset(self::$lists[$this->file])) {
            $lines = is_file($this->file) ? (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            self::$lists[$this->file] = array_fill_keys(array_map(static fn (string $line): string => strtolower(trim($line)), $lines), true);
        }

        return self::$lists[$this->file];
    }
}

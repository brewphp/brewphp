<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

use BrewPHP\Auth\Validators\PasswordValidator;
use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\ValidationError;
use BrewPHP\Support\Str;

/**
 * Password hashing and validation.
 *
 *     $hash = Passwords::make('correct horse battery staple');
 *     Passwords::check('correct horse battery staple', $hash);   // true
 *     Passwords::validate($password, $user);                     // ValidationError if too weak
 *
 * Hashes are stored as "<algorithm>$<hash>":
 * - `argon2id` (the default when PHP supports it): password_hash() with Argon2id;
 * - `bcrypt_sha256`: bcrypt of the SHA-256 of the password, so passwords
 *   longer than 72 bytes are not silently cut (bcrypt's limit).
 *
 * PASSWORD_HASHER picks the algorithm and PASSWORD_HASHER_OPTIONS its
 * password_hash() options. When they change, hashes are upgraded the next
 * time each user logs in (see needsRehash()).
 */
final class Passwords
{
    /** A hash that starts with this never matches a password. */
    public const UNUSABLE_PREFIX = '!';

    public static function make(string $password): string
    {
        $algorithm = self::algorithm();

        return $algorithm . '$' . match ($algorithm) {
            'argon2id' => password_hash($password, PASSWORD_ARGON2ID, self::options()),
            default => password_hash(self::prehash($password), PASSWORD_BCRYPT, self::options()),
        };
    }

    /**
     * Whether a password matches a hash (in constant time).
     */
    public static function check(string $password, ?string $encoded): bool
    {
        if ($encoded === null || !self::isUsable($encoded)) {
            return false;
        }
        [$algorithm, $hash] = array_pad(explode('$', $encoded, 2), 2, '');

        return match ($algorithm) {
            'argon2id' => password_verify($password, $hash),
            'bcrypt_sha256' => password_verify(self::prehash($password), $hash),
            default => false,
        };
    }

    /**
     * Whether a hash uses another algorithm or other options than the
     * current settings (it is then replaced at the next successful login).
     */
    public static function needsRehash(string $encoded): bool
    {
        [$algorithm, $hash] = array_pad(explode('$', $encoded, 2), 2, '');
        if ($algorithm !== self::algorithm()) {
            return true;
        }

        return password_needs_rehash($hash, $algorithm === 'argon2id' ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT, self::options());
    }

    /**
     * A value that no password matches, for accounts that log in another
     * way (or not at all).
     */
    public static function unusable(): string
    {
        return self::UNUSABLE_PREFIX . Str::random(40);
    }

    public static function isUsable(?string $encoded): bool
    {
        return $encoded !== null && $encoded !== '' && !str_starts_with($encoded, self::UNUSABLE_PREFIX);
    }

    /**
     * Hash a dummy password: used when a user does not exist, so that
     * logins take the same time either way (no user enumeration by timing).
     */
    public static function runDummyHash(string $password): void
    {
        static $dummy = null;
        $dummy ??= self::make('brewphp-dummy-password');
        self::check($password, $dummy);
    }

    /**
     * Check a new password against AUTH_PASSWORD_VALIDATORS.
     *
     * @throws ValidationError with every failed rule
     */
    public static function validate(string $password, ?Authenticatable $user = null): void
    {
        $messages = [];
        foreach (self::validators() as $validator) {
            try {
                $validator->validate($password, $user);
            } catch (ValidationError $e) {
                array_push($messages, ...$e->messages());
            }
        }
        if ($messages !== []) {
            throw new ValidationError($messages, 'password_invalid');
        }
    }

    /**
     * The rules of AUTH_PASSWORD_VALIDATORS, for help texts.
     *
     * @return list<string>
     */
    public static function helpTexts(): array
    {
        return array_map(static fn (PasswordValidator $v): string => $v->helpText(), self::validators());
    }

    /**
     * AUTH_PASSWORD_VALIDATORS: validator objects or class names.
     *
     * @return list<PasswordValidator>
     */
    public static function validators(): array
    {
        $validators = [];
        $entries = Application::hasInstance() ? Application::getInstance()->settings()->array('AUTH_PASSWORD_VALIDATORS') : [];
        foreach ($entries as $entry) {
            if (is_string($entry) && is_subclass_of($entry, PasswordValidator::class)) {
                $entry = new $entry();
            }
            if (!$entry instanceof PasswordValidator) {
                throw new ImproperlyConfigured(sprintf('AUTH_PASSWORD_VALIDATORS entries must be %s objects or class names.', PasswordValidator::class));
            }
            $validators[] = $entry;
        }

        return $validators;
    }

    private static function algorithm(): string
    {
        $setting = Application::hasInstance() ? Application::getInstance()->settings()->get('PASSWORD_HASHER') : null;
        $algorithm = $setting ?? (defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt_sha256');
        if ($algorithm === 'bcrypt') {
            $algorithm = 'bcrypt_sha256';
        }
        if (!in_array($algorithm, ['argon2id', 'bcrypt_sha256'], true)) {
            throw new ImproperlyConfigured(sprintf('PASSWORD_HASHER must be "argon2id" or "bcrypt", got %s.', var_export($setting, true)));
        }
        if ($algorithm === 'argon2id' && !defined('PASSWORD_ARGON2ID')) {
            throw new ImproperlyConfigured('PASSWORD_HASHER is "argon2id" but this PHP build has no Argon2 support. Use "bcrypt".');
        }

        return $algorithm;
    }

    /**
     * @return array<string, int>
     */
    private static function options(): array
    {
        $options = Application::hasInstance() ? Application::getInstance()->settings()->array('PASSWORD_HASHER_OPTIONS') : [];

        return array_filter($options, static fn (mixed $value): bool => is_int($value));
    }

    private static function prehash(string $password): string
    {
        // Base64, so the input never contains a NUL byte (which bcrypt would stop at).
        return base64_encode(hash('sha256', $password, true));
    }
}

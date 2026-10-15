<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Http\Request;

/**
 * CSRF tokens, as in Django.
 *
 * A random secret is kept in the `csrftoken` cookie (or the session). Each
 * page gets a *masked* token: the secret combined with a fresh random mask,
 * so the token in the HTML changes on every response (this defeats the
 * BREACH compression attack) while still proving knowledge of the secret.
 *
 *     Csrf::token($request);   // for a form field or an X-CSRFToken header
 *     Csrf::rotate($request);  // new secret, e.g. after logging in
 *
 * In templates, `{% csrf_token %}` prints the hidden form field.
 */
final class Csrf
{
    public const SECRET_LENGTH = 32;

    public const TOKEN_LENGTH = 2 * self::SECRET_LENGTH;

    public const CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** The form field name of the token (as in Django). */
    public const FIELD = 'csrfmiddlewaretoken';

    /** Request attribute holding the secret. */
    public const SECRET_ATTRIBUTE = '_csrf_secret';

    /** Request attribute set when a token was used, so the cookie must be sent. */
    public const USED_ATTRIBUTE = '_csrf_used';

    /**
     * A masked token for this request. Creates the secret if there is none.
     */
    public static function token(Request $request): string
    {
        $secret = $request->attribute(self::SECRET_ATTRIBUTE);
        if (!is_string($secret) || !self::isValidSecret($secret)) {
            $secret = self::generateSecret();
            $request->setAttribute(self::SECRET_ATTRIBUTE, $secret);
        }
        $request->setAttribute(self::USED_ATTRIBUTE, true);

        return self::mask($secret);
    }

    /**
     * Replace the secret, so tokens from before are no longer valid. Done on
     * login, so a token planted before login cannot be used afterwards.
     */
    public static function rotate(Request $request): void
    {
        $request->setAttribute(self::SECRET_ATTRIBUTE, self::generateSecret());
        $request->setAttribute(self::USED_ATTRIBUTE, true);
    }

    public static function generateSecret(): string
    {
        $secret = '';
        $max = strlen(self::CHARS) - 1;
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::CHARS[random_int(0, $max)];
        }

        return $secret;
    }

    public static function isValidSecret(string $value): bool
    {
        return strlen($value) === self::SECRET_LENGTH && strspn($value, self::CHARS) === self::SECRET_LENGTH;
    }

    /**
     * The secret combined with a random mask: mask . cipher.
     */
    public static function mask(string $secret): string
    {
        $mask = self::generateSecret();
        $cipher = '';
        $count = strlen(self::CHARS);
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $cipher .= self::CHARS[(strpos(self::CHARS, $secret[$i]) + strpos(self::CHARS, $mask[$i])) % $count];
        }

        return $mask . $cipher;
    }

    /**
     * The secret inside a masked token.
     */
    public static function unmask(string $token): string
    {
        $mask = substr($token, 0, self::SECRET_LENGTH);
        $cipher = substr($token, self::SECRET_LENGTH);
        $secret = '';
        $count = strlen(self::CHARS);
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::CHARS[(strpos(self::CHARS, $cipher[$i]) - strpos(self::CHARS, $mask[$i]) + $count) % $count];
        }

        return $secret;
    }

    /**
     * Whether a token from a form or header proves knowledge of the secret.
     * Masked (64 characters) and unmasked (32) tokens are accepted.
     */
    public static function matches(string $token, string $secret): bool
    {
        if (strspn($token, self::CHARS) !== strlen($token) || !self::isValidSecret($secret)) {
            return false;
        }

        return match (strlen($token)) {
            self::TOKEN_LENGTH => hash_equals($secret, self::unmask($token)),
            self::SECRET_LENGTH => hash_equals($secret, $token),
            default => false,
        };
    }
}

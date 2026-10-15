<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Validators;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;

/**
 * The password cannot be too similar to the user's username, names or
 * email address (or the parts of them).
 */
final class UserAttributeSimilarityValidator implements PasswordValidator
{
    /**
     * @param list<string> $attributes user fields to compare with
     * @param float $maxSimilarity 0.1 (strict) to 1.0 (only identical values fail)
     */
    public function __construct(
        public readonly array $attributes = ['username', 'first_name', 'last_name', 'email'],
        public readonly float $maxSimilarity = 0.7,
    ) {
        if ($maxSimilarity < 0.1) {
            throw new \InvalidArgumentException('maxSimilarity must be at least 0.1.');
        }
    }

    public function validate(string $password, ?Authenticatable $user = null): void
    {
        if (!$user instanceof Model) {
            return;
        }
        $password = strtolower($password);
        foreach ($this->attributes as $attribute) {
            $value = $user::meta()->hasField($attribute) ? $user->getAttribute($user::meta()->field($attribute)->attname) : null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $value = strtolower($value);
            $parts = array_merge([$value], preg_split('/\W+/', $value) ?: []);
            foreach ($parts as $part) {
                if (strlen($part) < 3) {
                    continue;
                }
                similar_text($password, $part, $percent);
                if ($percent / 100 >= $this->maxSimilarity || (strlen($part) >= 4 && str_contains($password, $part))) {
                    $label = $user::meta()->field($attribute)->label();
                    throw new ValidationError(sprintf('The password is too similar to the %s.', $label), 'password_too_similar');
                }
            }
        }
    }

    public function helpText(): string
    {
        return 'Your password can’t be too similar to your other personal information.';
    }
}

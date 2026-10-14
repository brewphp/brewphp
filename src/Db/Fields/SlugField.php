<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;

/**
 * A short label for URLs: letters, numbers, underscores and hyphens.
 * Indexed by default.
 */
class SlugField extends CharField
{
    public function __construct(int $maxLength = 50, bool $dbIndex = true, mixed ...$options)
    {
        parent::__construct($maxLength, ...$options, dbIndex: $dbIndex);
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if (is_string($value) && $value !== '' && preg_match('/^[-a-zA-Z0-9_]+$/', $value) !== 1) {
            throw new ValidationError('Enter a valid "slug" consisting of letters, numbers, underscores or hyphens.', 'invalid');
        }
    }
}

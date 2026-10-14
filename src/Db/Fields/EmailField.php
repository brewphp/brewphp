<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;

/**
 * An email address (a CharField that validates the format).
 */
class EmailField extends CharField
{
    public function __construct(int $maxLength = 254, mixed ...$options)
    {
        parent::__construct($maxLength, ...$options);
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError('Enter a valid email address.', 'invalid');
        }
    }
}

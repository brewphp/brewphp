<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;

/**
 * A floating-point number. Use DecimalField for money.
 */
class FloatField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('float');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (float) $value;
        }

        throw new ValidationError(sprintf('"%s" value must be a float.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
    }
}

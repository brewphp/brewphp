<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * A whole number.
 */
class IntegerField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('integer');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value instanceof \BackedEnum) {
            return $value === '' ? null : parent::toPhp($value);
        }
        if (is_int($value)) {
            return parent::toPhp($value);
        }
        if (is_bool($value) || (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) || (is_float($value) && floor($value) === $value)) {
            return parent::toPhp((int) $value);
        }

        throw new ValidationError(sprintf('"%s" value must be an integer.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        $minimum = $this->minimum();
        if ($minimum !== null && is_int($value) && $value < $minimum) {
            throw new ValidationError(sprintf('Ensure this value is greater than or equal to %d.', $minimum), 'min_value');
        }
    }

    /**
     * The smallest value allowed by validation, or null for no limit.
     */
    protected function minimum(): ?int
    {
        return null;
    }
}

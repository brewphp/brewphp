<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;

/**
 * Long text without a length limit.
 */
class TextField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('text');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value instanceof \BackedEnum || $this->enumClass() !== null) {
            return parent::toPhp($value);
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new ValidationError(sprintf('Expected text, got %s.', get_debug_type($value)), 'invalid');
    }

    protected function emptyValue(): mixed
    {
        return '';
    }
}

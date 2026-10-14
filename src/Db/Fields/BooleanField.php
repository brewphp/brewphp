<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;

/**
 * True or false. Form-style strings ("1", "0", "true", "on"...) are converted.
 */
class BooleanField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('boolean');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $text = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return match ($text) {
            '1', 'true', 't', 'yes', 'on' => true,
            '0', 'false', 'f', 'no', 'off' => false,
            '' => $this->null ? null : false,
            default => throw new ValidationError(sprintf('"%s" value must be either true or false.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid'),
        };
    }
}

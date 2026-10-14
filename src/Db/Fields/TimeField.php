<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;

/**
 * A time of day. PHP has no time type, so the value is a string such as
 * "09:30:00"; DateTime objects and strings like "9:30" are converted.
 */
class TimeField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('time');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        if (is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/', trim($value), $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60 && (int) ($m[3] ?? 0) < 60) {
            return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
        }

        throw new ValidationError(sprintf('"%s" value has an invalid time format.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
    }

    public function toDb(mixed $value): mixed
    {
        return $this->toPhp($value);
    }
}

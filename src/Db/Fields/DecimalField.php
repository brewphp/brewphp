<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * A fixed-precision decimal number, for money and the like. The PHP value
 * is a numeric string ("19.90") so no precision is lost to floats.
 */
class DecimalField extends Field
{
    /**
     * @param int $maxDigits digits in total, before and after the point
     * @param int $decimalPlaces digits after the point
     */
    public function __construct(public readonly int $maxDigits = 10, public readonly int $decimalPlaces = 2, mixed ...$options)
    {
        if ($decimalPlaces > $maxDigits) {
            throw new \InvalidArgumentException('decimalPlaces cannot be greater than maxDigits.');
        }
        parent::__construct(...$options);
    }

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('decimal', $this->maxDigits, $this->decimalPlaces);
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, $this->decimalPlaces, '.', '');
        }
        if (is_string($value) && preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?\s*$/', $value, $m) === 1 && ($m[2] !== '' || ($m[3] ?? '') !== '')) {
            $fraction = substr(str_pad($m[3] ?? '', $this->decimalPlaces, '0'), 0, max($this->decimalPlaces, strlen(rtrim($m[3] ?? '', '0'))));
            $integer = ltrim($m[2], '0') ?: '0';

            return ($m[1] === '-' ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : '');
        }

        throw new ValidationError(sprintf('"%s" value must be a decimal number.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if (!is_string($value) || $value === '') {
            return;
        }
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+-')), 2, '');
        $fraction = rtrim($fraction, '0');
        $integer = ltrim($integer, '0');
        if (strlen($fraction) > $this->decimalPlaces) {
            throw new ValidationError(sprintf('Ensure that there are no more than %d decimal places.', $this->decimalPlaces), 'max_decimal_places');
        }
        if (strlen($integer) > $this->maxDigits - $this->decimalPlaces) {
            throw new ValidationError(sprintf('Ensure that there are no more than %d digits before the decimal point.', $this->maxDigits - $this->decimalPlaces), 'max_whole_digits');
        }
    }
}

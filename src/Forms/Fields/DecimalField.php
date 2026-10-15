<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\NumberInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * An exact decimal number, kept as a string such as "12.50" so no
 * precision is lost.
 */
class DecimalField extends IntegerField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a number.',
        'max_digits' => 'Ensure that there are no more than {max} digits in total.',
        'max_decimal_places' => 'Ensure that there are no more than {max} decimal places.',
        'max_whole_digits' => 'Ensure that there are no more than {max} digits before the decimal point.',
    ] + parent::MESSAGES;

    public function __construct(public ?int $maxDigits = null, public ?int $decimalPlaces = null, mixed ...$options)
    {
        parent::__construct(...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^([-+])?(\d*)(?:\.(\d*))?$/', $text, $match) !== 1 || ($match[2] === '' && ($match[3] ?? '') === '')) {
            throw $this->error('invalid');
        }
        $whole = ltrim($match[2], '0');
        $fraction = $match[3] ?? '';

        return ($match[1] === '-' ? '-' : '') . ($whole === '' ? '0' : $whole) . ($fraction !== '' ? '.' . $fraction : '');
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (!is_string($value)) {
            return;
        }
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $wholeDigits = $whole === '0' ? 0 : strlen($whole);
        $decimals = strlen(rtrim($fraction, '0'));
        if ($this->maxDigits !== null && $wholeDigits + $decimals > $this->maxDigits) {
            throw $this->error('max_digits', ['max' => $this->maxDigits]);
        }
        if ($this->decimalPlaces !== null && $decimals > $this->decimalPlaces) {
            throw $this->error('max_decimal_places', ['max' => $this->decimalPlaces]);
        }
        if ($this->maxDigits !== null && $this->decimalPlaces !== null && $wholeDigits > $this->maxDigits - $this->decimalPlaces) {
            throw $this->error('max_whole_digits', ['max' => $this->maxDigits - $this->decimalPlaces]);
        }
    }

    public function widgetAttrs(Widget $widget): array
    {
        if (!$widget instanceof NumberInput) {
            return [];
        }
        $step = $this->decimalPlaces === null ? 'any' : ($this->decimalPlaces === 0 ? '1' : '0.' . str_repeat('0', $this->decimalPlaces - 1) . '1');

        return [...parent::widgetAttrs($widget), 'step' => $step];
    }
}

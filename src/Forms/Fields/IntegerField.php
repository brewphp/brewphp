<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\NumberInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A whole number, optionally between `minValue` and `maxValue`.
 */
class IntegerField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'Enter a whole number.',
        'min_value' => 'Ensure this value is greater than or equal to {limit}.',
        'max_value' => 'Ensure this value is less than or equal to {limit}.',
    ];

    public function __construct(public int|float|null $minValue = null, public int|float|null $maxValue = null, mixed ...$options)
    {
        parent::__construct(...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        // "5" and "5.0" are fine; "5.5", "1e3" and "0x10" are not.
        if (preg_match('/^[-+]?\d+(\.0*)?$/', $text) !== 1) {
            throw $this->error('invalid');
        }
        $number = filter_var(preg_replace('/\.0*$/', '', $text), FILTER_VALIDATE_INT);
        if ($number === false) {
            throw $this->error('invalid');
        }

        return $number;
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        $this->checkRange($value);
    }

    public function widgetAttrs(Widget $widget): array
    {
        return $widget instanceof NumberInput ? ['min' => $this->minValue, 'max' => $this->maxValue] : [];
    }

    protected function checkRange(mixed $value): void
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return;
        }
        if ($this->minValue !== null && (float) $value < $this->minValue) {
            throw $this->error('min_value', ['limit' => $this->minValue]);
        }
        if ($this->maxValue !== null && (float) $value > $this->maxValue) {
            throw $this->error('max_value', ['limit' => $this->maxValue]);
        }
    }

    protected function defaultWidget(): Widget
    {
        return new NumberInput();
    }
}

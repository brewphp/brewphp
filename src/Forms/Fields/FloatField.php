<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\NumberInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A number with decimals, as a PHP float.
 */
class FloatField extends IntegerField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a number.',
    ] + parent::MESSAGES;

    public function toPhp(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        $number = filter_var($text, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite($number)) {
            throw $this->error('invalid');
        }

        return $number;
    }

    public function widgetAttrs(Widget $widget): array
    {
        return $widget instanceof NumberInput ? [...parent::widgetAttrs($widget), 'step' => 'any'] : [];
    }
}

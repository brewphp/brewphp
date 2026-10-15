<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * An <input> element; subclasses set the type.
 */
class Input extends Widget
{
    protected string $inputType = 'text';

    public function render(string $name, mixed $value, array $attrs = []): string
    {
        $formatted = $this->formatValue($value);

        return Html::tag('input', $this->buildAttrs([
            'type' => $this->inputType,
            'name' => $name,
            'value' => $formatted,
            ...$attrs,
        ]));
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * <input type="checkbox">. Browsers send nothing for an unchecked box,
 * so a missing value means false.
 */
class CheckboxInput extends Widget
{
    public function render(string $name, mixed $value, array $attrs = []): string
    {
        return Html::tag('input', $this->buildAttrs([
            'type' => 'checkbox',
            'name' => $name,
            'checked' => self::isChecked($value),
            ...$attrs,
        ]));
    }

    public function valueFromData(array $data, array $files, string $name): mixed
    {
        if (!array_key_exists($name, $data)) {
            return false;
        }
        $value = $data[$name];

        return is_string($value) ? !in_array(strtolower($value), ['', '0', 'false', 'off'], true) : (bool) $value;
    }

    public function valueOmittedFromData(array $data, array $files, string $name): bool
    {
        // An unchecked box sends nothing, so the value is never "omitted".
        return false;
    }

    private static function isChecked(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'on'], true));
    }
}

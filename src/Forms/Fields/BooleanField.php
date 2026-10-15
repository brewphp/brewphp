<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\CheckboxInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A checkbox. With `required: true` (the default) it must be checked, as for
 * "I accept the terms"; use `required: false` for a plain on/off value.
 */
class BooleanField extends Field
{
    public function toPhp(mixed $value): mixed
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
        }

        if (is_array($value) || is_object($value)) {
            throw $this->error('invalid');
        }

        return (bool) $value;
    }

    public function validate(mixed $value): void
    {
        if ($this->required && $value !== true) {
            throw $this->error('required');
        }
    }

    protected function defaultWidget(): Widget
    {
        return new CheckboxInput();
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * A list of checkboxes, one per choice. The field name gets "[]".
 */
class CheckboxSelectMultiple extends RadioSelect
{
    protected string $inputType = 'checkbox';

    protected bool $multiple = true;

    public function useRequiredAttribute(mixed $initial): bool
    {
        return false;
    }
}

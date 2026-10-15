<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <input type="date">, with the value as YYYY-MM-DD.
 */
class DateInput extends Input
{
    protected string $inputType = 'date';

    protected string $dateFormat = 'Y-m-d';
}

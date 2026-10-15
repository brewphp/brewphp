<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <input type="datetime-local">, with the value as YYYY-MM-DDTHH:MM:SS.
 */
class DateTimeInput extends Input
{
    protected string $inputType = 'datetime-local';

    protected string $dateFormat = 'Y-m-d\TH:i:s';
}

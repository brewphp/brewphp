<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <input type="time">.
 */
class TimeInput extends Input
{
    protected string $inputType = 'time';

    protected string $dateFormat = 'H:i:s';
}

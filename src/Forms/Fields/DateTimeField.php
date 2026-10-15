<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\DateTimeInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A date and time, cleaned to a DateTimeImmutable in the TIME_ZONE.
 * Accepts what <input type="datetime-local"> sends and "Y-m-d H:i[:s]".
 */
class DateTimeField extends DateField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid date/time.',
    ];

    protected const FORMATS = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];

    protected function normalize(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value;
    }

    protected function defaultWidget(): Widget
    {
        return new DateTimeInput();
    }
}

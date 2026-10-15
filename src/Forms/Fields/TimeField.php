<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\TimeInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A time of day, cleaned to "HH:MM:SS".
 */
class TimeField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid time.',
    ];

    public function toPhp(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $text, $match) !== 1) {
            throw $this->error('invalid');
        }

        return sprintf('%s:%s:%s', $match[1], $match[2], ($match[3] ?? '') !== '' ? $match[3] : '00');
    }

    protected function defaultWidget(): Widget
    {
        return new TimeInput();
    }
}

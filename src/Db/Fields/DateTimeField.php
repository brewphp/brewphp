<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Db\Grammars\Grammar;

/**
 * A date and time, as a `DateTimeImmutable`. Values are stored as
 * "Y-m-d H:i:s" in the TIME_ZONE of the project (no time zone conversion),
 * to the second.
 */
class DateTimeField extends DateField
{
    protected const FORMAT = 'Y-m-d H:i:s';

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('datetime');
    }

    protected function normalize(\DateTimeImmutable $value): \DateTimeImmutable
    {
        $value = $value->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $value->setTime((int) $value->format('H'), (int) $value->format('i'), (int) $value->format('s'));
    }
}

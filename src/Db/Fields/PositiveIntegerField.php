<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

/**
 * A whole number that is zero or more (checked by validation).
 */
class PositiveIntegerField extends IntegerField
{
    protected function minimum(): ?int
    {
        return 0;
    }
}

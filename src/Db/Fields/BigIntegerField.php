<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Db\Grammars\Grammar;

/**
 * A 64-bit whole number.
 */
class BigIntegerField extends IntegerField
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('bigint');
    }
}

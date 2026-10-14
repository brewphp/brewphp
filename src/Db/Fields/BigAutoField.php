<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Db\Grammars\Grammar;

/**
 * A 64-bit auto-increment primary key; the type of the implicit `id`.
 */
class BigAutoField extends AutoField
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('bigint');
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The largest value.
 */
final class Max extends Aggregate
{
    public function function(): string
    {
        return 'MAX';
    }
}

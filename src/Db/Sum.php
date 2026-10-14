<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The sum of the values.
 */
final class Sum extends Aggregate
{
    public function function(): string
    {
        return 'SUM';
    }
}

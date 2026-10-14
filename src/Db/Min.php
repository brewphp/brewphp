<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The smallest value.
 */
final class Min extends Aggregate
{
    public function function(): string
    {
        return 'MIN';
    }
}

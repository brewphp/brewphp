<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The average of the values, as a float.
 */
final class Avg extends Aggregate
{
    public function function(): string
    {
        return 'AVG';
    }

    public function convert(mixed $value, ?Fields\Field $field): mixed
    {
        return $value === null ? null : (float) $value;
    }
}

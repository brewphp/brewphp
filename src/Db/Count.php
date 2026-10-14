<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The number of rows (or of non-null values of the field). Use '*' to count rows.
 */
final class Count extends Aggregate
{
    public function function(): string
    {
        return 'COUNT';
    }

    public function convert(mixed $value, ?Fields\Field $field): mixed
    {
        return (int) $value;
    }
}

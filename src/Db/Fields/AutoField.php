<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Db\Grammars\Grammar;

/**
 * An integer primary key that the database increments. Every model gets
 * one named `id` (a BigAutoField) unless a field has `primaryKey: true`.
 */
class AutoField extends IntegerField
{
    public function __construct(bool $primaryKey = true, bool $blank = true, bool $editable = false, mixed ...$options)
    {
        parent::__construct(...$options, primaryKey: $primaryKey, blank: $blank, editable: $editable);
    }

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('integer');
    }

    public function isAutoIncrement(): bool
    {
        return true;
    }
}

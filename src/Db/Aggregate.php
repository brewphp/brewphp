<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A summary value computed over the rows of a QuerySet:
 *
 *     Post::objects()->aggregate(total: new Sum('views'), posts: new Count('id'));
 *     // ['total' => 1520, 'posts' => 12]
 *
 * The field can follow relations: `new Avg('posts__views')`.
 */
abstract class Aggregate
{
    public function __construct(public readonly string $field, public readonly bool $distinct = false)
    {
    }

    /** The SQL function, e.g. "SUM". */
    abstract public function function(): string;

    /**
     * The default result key without a name: "views__sum".
     */
    public function defaultAlias(): string
    {
        return ($this->field === '*' ? 'all' : $this->field) . '__' . strtolower($this->function());
    }

    /**
     * Convert the database result; numeric types become int or float.
     */
    public function convert(mixed $value, ?Fields\Field $field): mixed
    {
        return $value === null || $field === null ? $value : $field->fromDb($value);
    }
}

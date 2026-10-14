<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * The state of a QuerySet: conditions, ordering, slicing and joins to load.
 * QuerySet clones it for every change, and Compiler turns it into SQL.
 *
 * @internal
 */
final class Query
{
    /** @var list<Q> conditions, combined with AND */
    public array $where = [];

    /** @var list<string>|null field paths ("-created_at"); null means the model's default ordering */
    public ?array $ordering = null;

    public ?int $limit = null;

    public int $offset = 0;

    /** @var list<string> forward relations to load with a JOIN */
    public array $selectRelated = [];

    public bool $distinct = false;

    /** True for `none()`: no query is run and there are no results. */
    public bool $empty = false;

    public function __construct(public readonly ModelMeta $meta)
    {
    }

    public function isSliced(): bool
    {
        return $this->limit !== null || $this->offset > 0;
    }

    /**
     * The ordering to use: the explicit one, else the model default.
     *
     * @return list<string>
     */
    public function effectiveOrdering(): array
    {
        return $this->ordering ?? $this->meta->ordering;
    }
}

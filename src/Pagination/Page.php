<?php

declare(strict_types=1);

namespace BrewPHP\Pagination;

/**
 * One page of a Paginator. Iterate over it to get the items.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     * @param Paginator<T> $paginator
     */
    public function __construct(
        public readonly array $items,
        public readonly int $number,
        public readonly Paginator $paginator,
    ) {
    }

    public function hasNext(): bool
    {
        return $this->number < $this->paginator->numPages();
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function hasOtherPages(): bool
    {
        return $this->hasNext() || $this->hasPrevious();
    }

    public function nextPageNumber(): int
    {
        if (!$this->hasNext()) {
            throw new EmptyPage('That page contains no results.');
        }

        return $this->number + 1;
    }

    public function previousPageNumber(): int
    {
        if (!$this->hasPrevious()) {
            throw new EmptyPage('That page number is less than 1.');
        }

        return $this->number - 1;
    }

    /**
     * The 1-based position of the first item on this page ("showing 11-20").
     */
    public function startIndex(): int
    {
        return $this->paginator->count() === 0 ? 0 : ($this->number - 1) * $this->paginator->perPage + 1;
    }

    /**
     * The 1-based position of the last item on this page.
     */
    public function endIndex(): int
    {
        return $this->paginator->count() === 0 ? 0 : $this->startIndex() + count($this->items) - 1;
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}

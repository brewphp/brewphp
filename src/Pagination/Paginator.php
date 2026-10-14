<?php

declare(strict_types=1);

namespace BrewPHP\Pagination;

use BrewPHP\Db\QuerySet;

/**
 * Splits a QuerySet (or an array) into pages, like Django's Paginator.
 *
 *     $paginator = new Paginator(Post::objects()->orderBy('-id'), perPage: 10);
 *     $page = $paginator->getPage($request->query('page'));
 *
 *     foreach ($page as $post) { ... }
 *     $page->hasNext();  $page->nextPageNumber();  $paginator->numPages();
 *
 * A QuerySet is counted with COUNT(*) and each page is loaded with
 * LIMIT/OFFSET, so only one page of rows is read.
 *
 * @template T
 */
final class Paginator
{
    private ?int $count = null;

    /**
     * @param QuerySet<T&\BrewPHP\Db\Model>|array<T> $items
     * @param int $orphans when the last page would have this many items or fewer, they join the previous page
     */
    public function __construct(
        private readonly QuerySet|array $items,
        public readonly int $perPage,
        public readonly int $orphans = 0,
        public readonly bool $allowEmptyFirstPage = true,
    ) {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('perPage must be at least 1.');
        }
    }

    /**
     * The total number of items.
     */
    public function count(): int
    {
        return $this->count ??= is_array($this->items) ? count($this->items) : $this->items->count();
    }

    public function numPages(): int
    {
        $count = $this->count();
        if ($count === 0 && !$this->allowEmptyFirstPage) {
            return 0;
        }

        return max(1, (int) ceil(max(1, $count - $this->orphans) / $this->perPage));
    }

    /**
     * The page numbers, from 1.
     *
     * @return list<int>
     */
    public function pageRange(): array
    {
        return $this->numPages() > 0 ? range(1, $this->numPages()) : [];
    }

    /**
     * A page by number; throws for invalid or out-of-range numbers.
     *
     * @return Page<T>
     * @throws PageNotAnInteger
     * @throws EmptyPage
     */
    public function page(mixed $number): Page
    {
        $number = $this->validateNumber($number);
        $offset = ($number - 1) * $this->perPage;
        $limit = $this->perPage;
        if ($number === $this->numPages()) {
            $limit += $this->orphans;
        }
        $items = is_array($this->items)
            ? array_slice(array_values($this->items), $offset, $limit)
            : $this->items->slice($offset, $limit)->toArray();

        /** @var list<T> $items */
        return new Page($items, $number, $this);
    }

    /**
     * A page by number, forgiving bad input: the first page for anything
     * that is not a number, the last page for numbers that are too large.
     *
     * @return Page<T>
     */
    public function getPage(mixed $number): Page
    {
        try {
            return $this->page($number);
        } catch (PageNotAnInteger) {
            return $this->page(1);
        } catch (EmptyPage) {
            return $this->page(max(1, $this->numPages()));
        }
    }

    /**
     * Page numbers to show around the current one, with null for gaps:
     * `[1, null, 4, 5, 6, null, 20]`.
     *
     * @return list<int|null>
     */
    public function elidedPageRange(int $number, int $onEachSide = 2, int $onEnds = 1): array
    {
        $last = $this->numPages();
        $shown = [];
        foreach ($this->pageRange() as $page) {
            if ($page <= $onEnds || $page > $last - $onEnds || abs($page - $number) <= $onEachSide) {
                if ($shown !== [] && end($shown) !== null && $page - (int) end($shown) > 1) {
                    $shown[] = null;
                }
                $shown[] = $page;
            }
        }

        return $shown;
    }

    private function validateNumber(mixed $number): int
    {
        if (is_string($number)) {
            $number = trim($number);
        }
        if (is_float($number) && floor($number) === $number) {
            $number = (int) $number;
        }
        if (is_string($number) && preg_match('/^-?\d+$/', $number) === 1) {
            $number = (int) $number;
        }
        if (!is_int($number)) {
            throw new PageNotAnInteger('That page number is not an integer.');
        }
        if ($number < 1) {
            throw new EmptyPage('That page number is less than 1.');
        }
        if ($number > $this->numPages() && !($number === 1 && $this->allowEmptyFirstPage)) {
            throw new EmptyPage('That page contains no results.');
        }

        return $number;
    }
}

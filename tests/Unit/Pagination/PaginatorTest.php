<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Pagination;

use BrewPHP\Pagination\EmptyPage;
use BrewPHP\Pagination\InvalidPage;
use BrewPHP\Pagination\PageNotAnInteger;
use BrewPHP\Pagination\Paginator;
use BrewPHP\Tests\TestCase;

final class PaginatorTest extends TestCase
{
    public function testPagesOfAnArray(): void
    {
        $paginator = new Paginator(range(1, 23), perPage: 10);

        self::assertSame(23, $paginator->count());
        self::assertSame(3, $paginator->numPages());
        self::assertSame([1, 2, 3], $paginator->pageRange());

        $first = $paginator->page(1);
        self::assertSame(range(1, 10), $first->items);
        self::assertTrue($first->hasNext());
        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasOtherPages());
        self::assertSame(2, $first->nextPageNumber());
        self::assertSame(1, $first->startIndex());
        self::assertSame(10, $first->endIndex());

        $last = $paginator->page('3');
        self::assertSame([21, 22, 23], iterator_to_array($last));
        self::assertCount(3, $last);
        self::assertFalse($last->hasNext());
        self::assertSame(2, $last->previousPageNumber());
        self::assertSame(21, $last->startIndex());
        self::assertSame(23, $last->endIndex());
    }

    public function testOrphansJoinThePreviousPage(): void
    {
        $paginator = new Paginator(range(1, 23), perPage: 10, orphans: 3);

        self::assertSame(2, $paginator->numPages());
        self::assertSame(range(11, 23), $paginator->page(2)->items);
    }

    public function testEmptyLists(): void
    {
        $paginator = new Paginator([], perPage: 10);
        self::assertSame(1, $paginator->numPages());
        $page = $paginator->page(1);
        self::assertSame([], $page->items);
        self::assertSame(0, $page->startIndex());
        self::assertSame(0, $page->endIndex());
        self::assertFalse($page->hasOtherPages());

        $strict = new Paginator([], perPage: 10, allowEmptyFirstPage: false);
        self::assertSame(0, $strict->numPages());
        self::assertSame([], $strict->pageRange());
        $this->expectException(EmptyPage::class);
        $strict->page(1);
    }

    public function testInvalidNumbers(): void
    {
        $paginator = new Paginator(range(1, 5), perPage: 2);

        foreach (['abc', null, '1.5', []] as $bad) {
            try {
                $paginator->page($bad);
                self::fail('PageNotAnInteger expected');
            } catch (PageNotAnInteger $e) {
                self::assertInstanceOf(InvalidPage::class, $e);
            }
        }
        foreach ([0, -1, 4] as $bad) {
            try {
                $paginator->page($bad);
                self::fail('EmptyPage expected');
            } catch (EmptyPage) {
            }
        }
        self::assertSame(2, $paginator->page(2.0)->number);

        self::assertSame(1, $paginator->getPage('abc')->number);
        self::assertSame(3, $paginator->getPage(99)->number);
        self::assertSame(3, $paginator->getPage(-5)->number);
    }

    public function testNextAndPreviousOnTheEdges(): void
    {
        $page = (new Paginator([1], perPage: 5))->page(1);

        $this->expectException(EmptyPage::class);
        $page->nextPageNumber();
    }

    public function testElidedPageRange(): void
    {
        $paginator = new Paginator(range(1, 200), perPage: 10);

        self::assertSame([1, null, 8, 9, 10, 11, 12, null, 20], $paginator->elidedPageRange(10));
        self::assertSame([1, 2, 3, 4, null, 20], $paginator->elidedPageRange(2));
        self::assertSame([1, 2, 3], (new Paginator(range(1, 30), perPage: 10))->elidedPageRange(2));
    }

    public function testPerPageMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Paginator([], perPage: 0);
    }
}

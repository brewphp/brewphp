<?php

declare(strict_types=1);

namespace BrewPHP\Pagination;

/**
 * The page number is not a whole number, e.g. "?page=abc".
 */
final class PageNotAnInteger extends InvalidPage
{
}

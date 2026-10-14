<?php

declare(strict_types=1);

namespace BrewPHP\Pagination;

/**
 * The page number is below 1 or after the last page.
 */
final class EmptyPage extends InvalidPage
{
}

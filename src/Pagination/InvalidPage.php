<?php

declare(strict_types=1);

namespace BrewPHP\Pagination;

/**
 * A page number that cannot be used. Paginator::getPage() never throws it.
 */
class InvalidPage extends \InvalidArgumentException
{
}

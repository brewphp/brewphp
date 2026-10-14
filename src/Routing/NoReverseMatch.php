<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

/**
 * Raised by `reverse()` when no route matches the name and parameters.
 */
final class NoReverseMatch extends \RuntimeException
{
}

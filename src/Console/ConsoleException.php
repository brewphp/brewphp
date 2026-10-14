<?php

declare(strict_types=1);

namespace BrewPHP\Console;

/**
 * A usage error: unknown command, bad option, missing argument. The runner
 * prints the message and exits with status 2.
 */
final class ConsoleException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

/**
 * A problem with migrations: a missing dependency, a conflict, a history
 * that does not match the files, or an operation that cannot be reversed.
 */
class MigrationError extends \RuntimeException
{
}

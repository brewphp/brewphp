<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A constraint was violated: a duplicate unique value, a NULL in a
 * NOT NULL column or a broken foreign key (SQLSTATE class 23).
 */
final class IntegrityError extends QueryException
{
}

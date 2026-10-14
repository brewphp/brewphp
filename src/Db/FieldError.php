<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A query or model used a field name that does not exist, or a lookup
 * that the field does not support. This is a programming error.
 */
final class FieldError extends \LogicException
{
}

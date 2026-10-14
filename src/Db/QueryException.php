<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A database error, with the SQL that caused it. The original
 * `PDOException` is available as `getPrevious()`.
 */
class QueryException extends \RuntimeException
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(public readonly string $sql, public readonly array $bindings, \PDOException $previous)
    {
        parent::__construct(sprintf('%s (SQL: %s)', $previous->getMessage(), $sql), 0, $previous);
    }

    /** The SQLSTATE error code, e.g. "23000". */
    public function sqlState(): string
    {
        $previous = $this->getPrevious();

        return $previous instanceof \PDOException ? (string) $previous->getCode() : '';
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Run raw SQL, written by hand in a migration:
 *
 *     new Operations\RunSQL(
 *         "UPDATE blog_post SET status = 'draft' WHERE status IS NULL",
 *         reverseSql: [],  // nothing to undo
 *     )
 *
 * Each string is one statement. Without `reverseSql` (null), the
 * migration cannot be unapplied; an empty list makes undoing a no-op.
 * The SQL is run as written: never build it from user input.
 */
final class RunSQL extends Operation
{
    /** @var list<string> */
    public readonly array $sql;

    /** @var list<string>|null */
    public readonly ?array $reverseSql;

    /**
     * @param string|list<string> $sql
     * @param string|list<string>|null $reverseSql
     */
    public function __construct(string|array $sql, string|array|null $reverseSql = null)
    {
        $this->sql = is_string($sql) ? [$sql] : array_values($sql);
        $this->reverseSql = is_string($reverseSql) ? [$reverseSql] : ($reverseSql === null ? null : array_values($reverseSql));
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        foreach ($this->sql as $statement) {
            $editor->execute($statement);
        }
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        if ($this->reverseSql === null) {
            throw new MigrationError('This RunSQL operation has no reverseSql, so it cannot be undone.');
        }
        foreach ($this->reverseSql as $statement) {
            $editor->execute($statement);
        }
    }

    public function reversible(): bool
    {
        return $this->reverseSql !== null;
    }

    public function describe(): string
    {
        return 'Raw SQL operation';
    }
}

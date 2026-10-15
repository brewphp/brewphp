<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Connection;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Run PHP code in a migration, usually to change data (Django's RunPython):
 *
 *     new Operations\RunPHP(
 *         static function (Connection $db, ProjectState $state): void {
 *             $table = $state->get('blog', 'Post')->table;
 *             $db->statement("UPDATE $table SET slug = lower(title)");
 *         },
 *         backwards: RunPHP::noop(...),
 *     )
 *
 * The callables get the connection and the migration state at that point.
 * Prefer the state's table names to your current model classes: the
 * models change later, the migration must not. Without `backwards`, the
 * migration cannot be unapplied. `sqlmigrate` cannot show this operation.
 */
final class RunPHP extends Operation
{
    /** @var \Closure(Connection, ProjectState): void */
    private readonly \Closure $forwards;

    /** @var (\Closure(Connection, ProjectState): void)|null */
    private readonly ?\Closure $backwards;

    /**
     * @param callable(Connection, ProjectState): void $forwards
     * @param (callable(Connection, ProjectState): void)|null $backwards
     */
    public function __construct(callable $forwards, ?callable $backwards = null, private readonly string $description = 'PHP operation')
    {
        $this->forwards = \Closure::fromCallable($forwards);
        $this->backwards = $backwards === null ? null : \Closure::fromCallable($backwards);
    }

    /**
     * Does nothing: use it as `backwards` when undoing needs no work.
     */
    public static function noop(Connection $db, ProjectState $state): void
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        if (!$editor->isCollectOnly()) {
            ($this->forwards)($editor->connection(), $from);
        }
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        if ($this->backwards === null) {
            throw new MigrationError('This RunPHP operation has no backwards callable, so it cannot be undone.');
        }
        if (!$editor->isCollectOnly()) {
            ($this->backwards)($editor->connection(), $to);
        }
    }

    public function reversible(): bool
    {
        return $this->backwards !== null;
    }

    public function describe(): string
    {
        return $this->description;
    }
}

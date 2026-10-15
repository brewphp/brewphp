<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Migrations\Operations\Operation;

/**
 * One migration: a list of operations that move the database schema
 * forward one step, like Django's `Migration` class.
 *
 * A migration file in `apps/<app>/migrations/` returns an instance of an
 * anonymous subclass:
 *
 *     return new class extends Migration
 *     {
 *         public array $dependencies = ['blog.0001_initial'];
 *
 *         public function operations(): array
 *         {
 *             return [
 *                 new Operations\AddField('Post', 'subtitle', new Fields\CharField(maxLength: 200, default: '')),
 *             ];
 *         }
 *     };
 *
 * `makemigrations` writes these files; you can also write or edit them by
 * hand, for example to add a RunSQL or RunPHP operation.
 */
abstract class Migration
{
    /** The app label, set by the loader from the folder. */
    public string $app = '';

    /** The file name without ".php", e.g. "0001_initial", set by the loader. */
    public string $name = '';

    /** Whether this is the first migration of its app. */
    public bool $initial = false;

    /**
     * Run the migration inside one transaction (on databases that can roll
     * back schema changes: SQLite and PostgreSQL, not MySQL).
     */
    public bool $atomic = true;

    /**
     * Migrations that must run first, as "app.name" strings, e.g.
     * `['blog.0001_initial', 'auth.0001_initial']`.
     *
     * @var list<string>
     */
    public array $dependencies = [];

    /**
     * The operations, in order.
     *
     * @return list<Operation>
     */
    abstract public function operations(): array;

    /**
     * "blog.0001_initial".
     */
    public function key(): string
    {
        return $this->app . '.' . $this->name;
    }

    /**
     * Apply each operation to the state, as if the migration had run.
     */
    public function mutateState(ProjectState $state): void
    {
        foreach ($this->operations() as $operation) {
            $operation->stateForwards($this->app, $state);
        }
    }
}

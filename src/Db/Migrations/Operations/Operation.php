<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * One step of a migration, like Django's operations.
 *
 * An operation changes the project state (stateForwards()) and the
 * database (databaseForwards()), and can undo its database change
 * (databaseBackwards()). `$app` is the label of the migration's app.
 */
abstract class Operation
{
    /**
     * Change the state the way this operation changes the schema.
     */
    abstract public function stateForwards(string $app, ProjectState $state): void;

    /**
     * Change the database. `$from` is the state before the operation,
     * `$to` the state after it.
     */
    abstract public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void;

    /**
     * Undo the database change. `$from` is the state *with* the operation
     * applied (the current database), `$to` the state before it.
     */
    abstract public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void;

    /**
     * A short description for `migrate` and `sqlmigrate`, e.g. "Create model Post".
     */
    abstract public function describe(): string;

    /**
     * Whether databaseBackwards() can undo the operation.
     */
    public function reversible(): bool
    {
        return true;
    }

    /**
     * A part of an automatic migration name, e.g. "post_subtitle".
     */
    public function migrationNameFragment(): ?string
    {
        return null;
    }

    /**
     * The constructor arguments, by name, used to write the operation to
     * a migration file. Operations that hold closures return null: they
     * can only be written by hand.
     *
     * @return array<string, mixed>|null
     */
    public function arguments(): ?array
    {
        return null;
    }

    /**
     * A copy of `$field` attached to a model of `$state` under `$name`, as
     * the schema editor needs it. A foreign key is bound to its target in
     * the same state.
     */
    protected static function attach(Field $field, string $name, string $app, string $model, ProjectState $state): Field
    {
        $field = clone $field;
        $field->contributeTo('', $name);
        if ($field instanceof ForeignKey) {
            $target = $state->resolveReference($field->to, $state->get($app, $model));
            $field->bindTarget($state->meta($target->app, $target->name));
        }

        return $field;
    }
}

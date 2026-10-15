<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Rename a model's table.
 */
final class AlterModelTable extends Operation
{
    public function __construct(public readonly string $name, public readonly string $table)
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $state->get($app, $this->name)->table = $this->table;
        $state->changed();
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->renameModel($from->get($app, $this->name)->table, $this->table);
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->renameModel($this->table, $to->get($app, $this->name)->table);
    }

    public function describe(): string
    {
        return sprintf('Rename table of %s to %s', $this->name, $this->table);
    }

    public function migrationNameFragment(): string
    {
        return 'alter_' . strtolower($this->name) . '_table';
    }

    public function arguments(): array
    {
        return ['name' => $this->name, 'table' => $this->table];
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Drop a model's table. Undoing it creates an empty table again.
 */
final class DeleteModel extends Operation
{
    public function __construct(public readonly string $name)
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $state->remove($app, $this->name);
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->deleteModel($from->meta($app, $this->name));
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->createModel($to->meta($app, $this->name));
    }

    public function describe(): string
    {
        return 'Delete model ' . $this->name;
    }

    public function migrationNameFragment(): string
    {
        return 'delete_' . strtolower($this->name);
    }

    public function arguments(): array
    {
        return ['name' => $this->name];
    }
}

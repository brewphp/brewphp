<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Drop a column. Undoing it adds the column back (empty), so a non-null
 * field needs a default for that to work on a table with rows.
 */
final class RemoveField extends Operation
{
    public function __construct(public readonly string $model, public readonly string $name)
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $model = $state->get($app, $this->model);
        $model->field($this->name);
        foreach ($model->indexes as $index) {
            if (in_array($this->name, $index->fields, true)) {
                throw new MigrationError(sprintf('Cannot remove field "%s" of %s: an index uses it. Remove the index first.', $this->name, $model->label()));
            }
        }
        unset($model->fields[$this->name]);
        $state->changed();
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $meta = $from->meta($app, $this->model);
        $editor->removeField($meta, $meta->field($this->name));
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->addField($from->meta($app, $this->model), $to->meta($app, $this->model)->field($this->name));
    }

    public function describe(): string
    {
        return sprintf('Remove field %s from %s', $this->name, $this->model);
    }

    public function migrationNameFragment(): string
    {
        return sprintf('remove_%s_%s', strtolower($this->model), $this->name);
    }

    public function arguments(): array
    {
        return ['model' => $this->model, 'name' => $this->name];
    }
}

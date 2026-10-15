<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Migrations\Fields;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Add a column to a model's table.
 *
 * With `preserveDefault: false`, the field's default only fills the
 * existing rows (a one-off value given to `makemigrations`) and is not
 * kept in the migration state.
 */
final class AddField extends Operation
{
    public function __construct(
        public readonly string $model,
        public readonly string $name,
        public readonly Field $field,
        public readonly bool $preserveDefault = true,
    ) {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $model = $state->get($app, $this->model);
        if (isset($model->fields[$this->name])) {
            throw new MigrationError(sprintf('Model %s already has a field named "%s".', $model->label(), $this->name));
        }
        $model->fields[$this->name] = $this->preserveDefault ? clone $this->field : Fields::with($this->field, remove: ['default']);
        $state->changed();
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->addField($from->meta($app, $this->model), self::attach($this->field, $this->name, $app, $this->model, $to));
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $meta = $from->meta($app, $this->model);
        $editor->removeField($meta, $meta->field($this->name));
    }

    public function describe(): string
    {
        return sprintf('Add field %s to %s', $this->name, $this->model);
    }

    public function migrationNameFragment(): string
    {
        return strtolower($this->model) . '_' . $this->name;
    }

    public function arguments(): array
    {
        $arguments = ['model' => $this->model, 'name' => $this->name, 'field' => $this->field];
        if (!$this->preserveDefault) {
            $arguments['preserveDefault'] = false;
        }

        return $arguments;
    }
}

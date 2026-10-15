<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Migrations\Fields;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Change a column: its type, nullability, uniqueness, index or target.
 *
 * With `preserveDefault: false`, the default only fills rows that are
 * NULL when a column becomes NOT NULL, and is not kept in the state.
 */
final class AlterField extends Operation
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
        $model->field($this->name);
        $model->fields[$this->name] = $this->preserveDefault ? clone $this->field : Fields::with($this->field, remove: ['default']);
        $state->changed();
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $meta = $from->meta($app, $this->model);
        $editor->alterField($meta, $meta->field($this->name), self::attach($this->field, $this->name, $app, $this->model, $to));
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $meta = $from->meta($app, $this->model);
        $editor->alterField($meta, $meta->field($this->name), $to->meta($app, $this->model)->field($this->name));
    }

    public function describe(): string
    {
        return sprintf('Alter field %s on %s', $this->name, $this->model);
    }

    public function migrationNameFragment(): string
    {
        return sprintf('alter_%s_%s', strtolower($this->model), $this->name);
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

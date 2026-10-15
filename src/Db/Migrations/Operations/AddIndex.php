<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Create an index from Model::indexes().
 */
final class AddIndex extends Operation
{
    public function __construct(public readonly string $model, public readonly Index $index)
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $model = $state->get($app, $this->model);
        foreach ($this->index->fields as $field) {
            $model->field($field);
        }
        $model->indexes[] = $this->index;
        $state->changed();
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->addIndex($to->meta($app, $this->model), $this->index);
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->removeIndex($from->meta($app, $this->model), $this->index);
    }

    public function describe(): string
    {
        return sprintf('Create %sindex on %s (%s)', $this->index->unique ? 'unique ' : '', $this->model, implode(', ', $this->index->fields));
    }

    public function migrationNameFragment(): string
    {
        return strtolower($this->model) . '_' . ($this->index->name ?? implode('_', $this->index->fields) . ($this->index->unique ? '_uniq' : '_idx'));
    }

    public function arguments(): array
    {
        return ['model' => $this->model, 'index' => $this->index];
    }
}

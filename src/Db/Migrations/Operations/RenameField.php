<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Rename a field and its column (unless the column is set with dbColumn).
 * Indexes that use the field are dropped and created again, so their
 * generated names follow the new column name.
 */
final class RenameField extends Operation
{
    public function __construct(
        public readonly string $model,
        public readonly string $oldName,
        public readonly string $newName,
    ) {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        self::rename($app, $state, $this->model, $this->oldName, $this->newName);
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $this->renameColumn($app, $editor, $from, $to, $this->oldName, $this->newName);
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $this->renameColumn($app, $editor, $from, $to, $this->newName, $this->oldName);
    }

    public function describe(): string
    {
        return sprintf('Rename field %s on %s to %s', $this->oldName, $this->model, $this->newName);
    }

    public function migrationNameFragment(): string
    {
        return sprintf('rename_%s_%s_%s', $this->oldName, strtolower($this->model), $this->newName);
    }

    public function arguments(): array
    {
        return ['model' => $this->model, 'oldName' => $this->oldName, 'newName' => $this->newName];
    }

    private function renameColumn(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to, string $old, string $new): void
    {
        $before = $from->meta($app, $this->model);
        $after = $to->meta($app, $this->model);
        $affected = array_keys(array_filter($before->indexes, static fn (Index $index): bool => in_array($old, $index->fields, true)));
        foreach ($affected as $i) {
            $editor->removeIndex($before, $before->indexes[$i]);
        }
        $editor->alterField($before, $before->field($old), $after->field($new));
        foreach ($affected as $i) {
            $editor->addIndex($after, $after->indexes[$i]);
        }
    }

    private static function rename(string $app, ProjectState $state, string $modelName, string $old, string $new): void
    {
        $model = $state->get($app, $modelName);
        $model->field($old);
        if (isset($model->fields[$new])) {
            throw new MigrationError(sprintf('Model %s already has a field named "%s".', $model->label(), $new));
        }
        $fields = [];
        foreach ($model->fields as $name => $field) {
            $fields[$name === $old ? $new : $name] = $field;
        }
        $model->fields = $fields;
        $model->indexes = array_map(
            static fn (Index $index): Index => in_array($old, $index->fields, true)
                ? new Index(array_map(static fn (string $f): string => $f === $old ? $new : $f, $index->fields), $index->name, $index->unique)
                : $index,
            $model->indexes,
        );
        $state->changed();
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Migrations\Fields;
use BrewPHP\Db\Migrations\ModelState;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Rename a model in the migration state, and point foreign keys at the
 * new name. The table keeps its name: `makemigrations` adds an
 * AlterModelTable after this operation when the table changes too.
 */
final class RenameModel extends Operation
{
    public function __construct(public readonly string $oldName, public readonly string $newName)
    {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        self::rename($app, $state, $this->oldName, $this->newName);
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
    }

    public function describe(): string
    {
        return sprintf('Rename model %s to %s', $this->oldName, $this->newName);
    }

    public function migrationNameFragment(): string
    {
        return sprintf('rename_%s_%s', strtolower($this->oldName), strtolower($this->newName));
    }

    public function arguments(): array
    {
        return ['oldName' => $this->oldName, 'newName' => $this->newName];
    }

    private static function rename(string $app, ProjectState $state, string $old, string $new): void
    {
        $references = $state->referencesTo($app, $old);
        $model = $state->remove($app, $old);
        $state->add(new ModelState($app, $new, $model->fields, $model->table, $model->indexes));
        foreach ($references as [$referencing, $fieldName]) {
            $target = $referencing->key() === $model->key() ? $state->get($app, $new) : $referencing;
            $field = $target->fields[$fieldName];
            if ($field instanceof ForeignKey) {
                $target->fields[$fieldName] = Fields::with($field, ['to' => $app . '.' . $new]);
            }
        }
        $state->changed();
    }
}

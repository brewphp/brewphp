<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Index;
use BrewPHP\Db\ModelMeta;

/**
 * A model as migrations see it: its name, table, fields and indexes,
 * without a PHP class. Only what changes the database is recorded, so
 * changing a model's ordering or a field's help text needs no migration.
 */
final class ModelState
{
    /**
     * @param array<string, Field> $fields fields by name, primary key first
     * @param list<Index> $indexes
     */
    public function __construct(
        public string $app,
        public string $name,
        public array $fields,
        public string $table,
        public array $indexes = [],
    ) {
    }

    /**
     * The state of a model class, with every field rebuilt from what its
     * migration would record (see Field::deconstructForMigration()).
     */
    public static function fromMeta(ModelMeta $meta): self
    {
        $fields = [];
        foreach ($meta->fields as $name => $field) {
            $fields[$name] = Fields::forMigration($field);
        }

        return new self($meta->appLabel, $meta->modelName, $fields, $meta->table, array_values($meta->indexes));
    }

    /**
     * "blog.post": the lower-case key used by ProjectState.
     */
    public function key(): string
    {
        return strtolower($this->app . '.' . $this->name);
    }

    /**
     * "blog.Post".
     */
    public function label(): string
    {
        return $this->app . '.' . $this->name;
    }

    public function field(string $name): Field
    {
        return $this->fields[$name] ?? throw new MigrationError(sprintf('Model %s has no field "%s" in the migration state.', $this->label(), $name));
    }

    public function __clone()
    {
        foreach ($this->fields as $name => $field) {
            $this->fields[$name] = clone $field;
        }
    }
}

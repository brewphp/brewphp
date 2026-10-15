<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations\Operations;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\ModelState;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Schema\SchemaEditor;

/**
 * Create a model's table.
 *
 *     new CreateModel('Post', [
 *         'id' => new Fields\BigAutoField(),
 *         'title' => new Fields\CharField(maxLength: 200),
 *     ], table: 'blog_post')
 */
final class CreateModel extends Operation
{
    /**
     * @param array<string, Field> $fields
     * @param list<Index> $indexes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly string $table,
        public readonly array $indexes = [],
    ) {
    }

    public function stateForwards(string $app, ProjectState $state): void
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = clone $field;
        }
        $state->add(new ModelState($app, $this->name, $fields, $this->table, $this->indexes));
    }

    public function databaseForwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->createModel($to->meta($app, $this->name));
    }

    public function databaseBackwards(string $app, SchemaEditor $editor, ProjectState $from, ProjectState $to): void
    {
        $editor->deleteModel($from->meta($app, $this->name));
    }

    public function describe(): string
    {
        return 'Create model ' . $this->name;
    }

    public function migrationNameFragment(): string
    {
        return strtolower($this->name);
    }

    public function arguments(): array
    {
        $arguments = ['name' => $this->name, 'fields' => $this->fields, 'table' => $this->table];
        if ($this->indexes !== []) {
            $arguments['indexes'] = $this->indexes;
        }

        return $arguments;
    }
}

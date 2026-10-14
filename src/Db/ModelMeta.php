<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;

/**
 * Everything BrewPHP knows about a model's table: its name, fields, primary
 * key, ordering and indexes. Django calls this `Model._meta`.
 *
 * Get it with `Post::meta()`. The schema editor works on ModelMeta objects,
 * so they can also be built without a model class (migrations do that).
 */
final class ModelMeta
{
    /** @var array<string, Field> fields by name, primary key first */
    public readonly array $fields;

    public readonly Field $pk;

    /**
     * @param class-string<Model>|string $model the model class, or '' for a detached description
     * @param array<string, Field> $fields fields by name; an `id` BigAutoField is added without a primary key
     * @param list<string> $ordering default ordering, e.g. ['-created_at']
     * @param list<Index> $indexes
     */
    public function __construct(
        public readonly string $model,
        public readonly string $table,
        array $fields,
        public readonly string $appLabel = '',
        public readonly string $modelName = '',
        public readonly array $ordering = [],
        public readonly array $indexes = [],
        public readonly string $verboseName = '',
        public readonly string $verboseNamePlural = '',
    ) {
        $primaryKeys = array_keys(array_filter($fields, static fn (Field $field): bool => $field->primaryKey));
        if (count($primaryKeys) > 1) {
            throw new FieldError(sprintf('%s has more than one primary key: %s.', $this->name(), implode(', ', $primaryKeys)));
        }
        if ($primaryKeys === []) {
            if (isset($fields['id'])) {
                throw new FieldError(sprintf('%s has a field named "id" that is not a primary key. Add primaryKey: true or rename it.', $this->name()));
            }
            $fields = ['id' => new BigAutoField()] + $fields;
        }

        $columns = [];
        foreach ($fields as $name => $field) {
            if (!is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1 || str_contains($name, '__') || $name === 'pk') {
                throw new FieldError(sprintf('Invalid field name "%s" in %s: use letters, digits and single underscores ("pk" is reserved).', $name, $this->name()));
            }
            if (!$field instanceof Field) {
                throw new FieldError(sprintf('Field "%s" of %s must be a %s instance.', $name, $this->name(), Field::class));
            }
            $field->contributeTo($model, $name);
            if (isset($columns[$field->column])) {
                throw new FieldError(sprintf('Fields "%s" and "%s" of %s use the same column "%s".', $columns[$field->column], $name, $this->name(), $field->column));
            }
            $columns[$field->column] = $name;
        }

        /** @var array<string, Field> $fields */
        $this->fields = $fields;
        $this->pk = $fields[$primaryKeys[0] ?? 'id'];
    }

    /**
     * A field by name; "pk" is the primary key.
     */
    public function field(string $name): Field
    {
        if ($name === 'pk') {
            return $this->pk;
        }

        return $this->fields[$name] ?? throw new FieldError(sprintf(
            '%s has no field named "%s". Choices are: %s.',
            $this->name(),
            $name,
            implode(', ', array_keys($this->fields)),
        ));
    }

    public function hasField(string $name): bool
    {
        return $name === 'pk' || isset($this->fields[$name]);
    }

    /**
     * A field by its attribute name ("author_id") or name ("author").
     */
    public function fieldByAttname(string $attname): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->attname === $attname) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_map(static fn (Field $field): string => $field->column, $this->fields));
    }

    /**
     * The foreign keys of this model.
     *
     * @return array<string, ForeignKey>
     */
    public function foreignKeys(): array
    {
        return array_filter($this->fields, static fn (Field $field): bool => $field instanceof ForeignKey);
    }

    /**
     * Foreign keys of other loaded models that point to this one.
     *
     * @return list<ForeignKey>
     */
    public function reverseRelations(): array
    {
        return $this->model !== '' && is_subclass_of($this->model, Model::class) ? Registry::reverseRelations($this->model) : [];
    }

    /**
     * "blog.Post".
     */
    public function label(): string
    {
        return $this->appLabel . '.' . $this->modelName;
    }

    private function name(): string
    {
        return $this->model !== '' ? $this->model : $this->table;
    }
}

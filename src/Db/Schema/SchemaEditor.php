<?php

declare(strict_types=1);

namespace BrewPHP\Db\Schema;

use BrewPHP\Db\Connection;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Index;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;

/**
 * Creates and changes tables from model definitions:
 *
 *     $editor = db()->schemaEditor();
 *     $editor->createModel(Post::class);
 *     $editor->addField(Post::class, Post::meta()->field('subtitle'));
 *
 * Until migrations arrive (v0.4), this is how tables are created. Every
 * method accepts a model class or a ModelMeta. With `collectOnly`, the SQL
 * is collected in collectedSql() instead of being executed.
 *
 * The model (or ModelMeta) must describe the table as it is in the
 * database: SQLite rebuilds the table from it, so a column it does not
 * know about would be lost.
 *
 * Constraint and index names are generated from the table and columns
 * (Grammar::indexName()), so later changes can find them again.
 *
 * This base class writes standard SQL (PostgreSQL's dialect); MySQL and
 * SQLite override what differs.
 */
abstract class SchemaEditor
{
    protected readonly Grammar $grammar;

    /** @var list<string> */
    private array $collected = [];

    public function __construct(protected readonly Connection $connection, private readonly bool $collectOnly = false)
    {
        $this->grammar = $connection->grammar();
    }

    /**
     * Every statement run (or collected) by this editor, in order.
     *
     * @return list<string>
     */
    public function collectedSql(): array
    {
        return $this->collected;
    }

    // Tables --------------------------------------------------------------------

    /**
     * CREATE TABLE with its constraints and indexes.
     *
     * @param class-string<Model>|ModelMeta $model
     */
    public function createModel(string|ModelMeta $model): void
    {
        $meta = self::metaOf($model);
        $this->execute($this->createTableSql($meta->table, $meta, $meta->fields));
        $this->createIndexes($meta, $meta->fields);
    }

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function deleteModel(string|ModelMeta $model): void
    {
        $this->execute('DROP TABLE ' . $this->grammar->quoteIdentifier(self::metaOf($model)->table));
    }

    public function renameModel(string $oldTable, string $newTable): void
    {
        $this->execute(sprintf('ALTER TABLE %s RENAME TO %s', $this->grammar->quoteIdentifier($oldTable), $this->grammar->quoteIdentifier($newTable)));
    }

    // Fields --------------------------------------------------------------------

    /**
     * Add a column. A non-null field needs a (non-closure) default when the
     * table already has rows; it is used as the column DEFAULT.
     *
     * @param class-string<Model>|ModelMeta $model
     */
    public function addField(string|ModelMeta $model, Field $field): void
    {
        $meta = self::metaOf($model);
        self::assertAttached($field);
        $table = $this->grammar->quoteIdentifier($meta->table);
        $this->execute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->columnDefinition($field, withDefault: true));
        if ($field->unique && !$field->primaryKey) {
            $this->execute('ALTER TABLE ' . $table . ' ADD ' . $this->uniqueConstraint($meta->table, $field));
        }
        if ($field instanceof ForeignKey) {
            $this->execute('ALTER TABLE ' . $table . ' ADD ' . $this->foreignKeyConstraint($meta->table, $field));
        }
        if ($field->needsIndex()) {
            $this->execute($this->createIndexSql($meta->table, $this->grammar->indexName($meta->table, [$field->column], 'idx'), [$field->column], false));
        }
    }

    /**
     * Drop a column (and its constraints).
     *
     * @param class-string<Model>|ModelMeta $model
     */
    public function removeField(string|ModelMeta $model, Field $field): void
    {
        $meta = self::metaOf($model);
        self::assertAttached($field);
        $this->execute(sprintf('ALTER TABLE %s DROP COLUMN %s', $this->grammar->quoteIdentifier($meta->table), $this->grammar->quoteIdentifier($field->column)));
    }

    /**
     * Change a column from `$old` to `$new`: name, type, nullability,
     * uniqueness, index and foreign key.
     *
     * @param class-string<Model>|ModelMeta $model
     */
    public function alterField(string|ModelMeta $model, Field $old, Field $new): void
    {
        $meta = self::metaOf($model);
        self::assertAttached($old);
        self::assertAttached($new);
        if ($old->primaryKey !== $new->primaryKey) {
            throw new FieldError('Changing the primary key of a table is not supported.');
        }
        $table = $meta->table;
        $quoted = $this->grammar->quoteIdentifier($table);
        $renamed = $old->column !== $new->column;
        $typeChanged = $old->columnType($this->grammar) !== $new->columnType($this->grammar);
        $nullChanged = $old->null !== $new->null;
        $fkChanged = $renamed || $typeChanged || self::foreignKeyTarget($old) !== self::foreignKeyTarget($new);

        // Drop what the change invalidates...
        if ($old instanceof ForeignKey && ($fkChanged || !$new instanceof ForeignKey)) {
            $this->execute($this->dropForeignKeySql($table, $this->grammar->indexName($table, [$old->column], 'fk')));
        }
        if ($old->unique && !$old->primaryKey && ($renamed || !$new->unique)) {
            $this->execute($this->dropUniqueSql($table, $this->grammar->indexName($table, [$old->column], 'uniq')));
        }
        if ($old->needsIndex() && ($renamed || !$new->needsIndex())) {
            $this->execute($this->dropIndexSql($table, $this->grammar->indexName($table, [$old->column], 'idx')));
        }

        // ...change the column...
        if ($renamed) {
            $this->execute(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $quoted, $this->grammar->quoteIdentifier($old->column), $this->grammar->quoteIdentifier($new->column)));
        }
        if ($typeChanged || $nullChanged) {
            foreach ($this->alterColumnSql($table, $old, $new, $typeChanged, $nullChanged) as $sql) {
                $this->execute($sql);
            }
        }

        // ...and add back what the new definition needs.
        if ($new->unique && !$new->primaryKey && ($renamed || !$old->unique)) {
            $this->execute('ALTER TABLE ' . $quoted . ' ADD ' . $this->uniqueConstraint($table, $new));
        }
        if ($new->needsIndex() && ($renamed || !$old->needsIndex())) {
            $this->execute($this->createIndexSql($table, $this->grammar->indexName($table, [$new->column], 'idx'), [$new->column], false));
        }
        if ($new instanceof ForeignKey && ($fkChanged || !$old instanceof ForeignKey)) {
            $this->execute('ALTER TABLE ' . $quoted . ' ADD ' . $this->foreignKeyConstraint($table, $new));
        }
    }

    /**
     * Rename a field: its column and the names of its constraints.
     *
     * @param class-string<Model>|ModelMeta $model
     */
    public function renameField(string|ModelMeta $model, Field $field, string $newName): void
    {
        self::assertAttached($field);
        $renamed = clone $field;
        $renamed->contributeTo($field->model, $newName);
        $this->alterField($model, $field, $renamed);
    }

    // Indexes -------------------------------------------------------------------

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function addIndex(string|ModelMeta $model, Index $index): void
    {
        $meta = self::metaOf($model);
        $columns = $index->columns($meta);
        $this->execute($this->createIndexSql($meta->table, $index->nameFor($meta->table, $columns, $this->grammar), $columns, $index->unique));
    }

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function removeIndex(string|ModelMeta $model, Index $index): void
    {
        $meta = self::metaOf($model);
        $this->execute($this->dropIndexSql($meta->table, $index->nameFor($meta->table, $index->columns($meta), $this->grammar)));
    }

    // SQL pieces ----------------------------------------------------------------

    /**
     * `"title" varchar(200) NOT NULL`.
     */
    public function columnDefinition(Field $field, bool $withDefault = false, bool $withPrimaryKey = true): string
    {
        $column = $this->grammar->quoteIdentifier($field->column);
        if ($field->isAutoIncrement()) {
            return $column . ' ' . $this->grammar->autoIncrementColumn($field instanceof BigAutoField);
        }
        $sql = $column . ' ' . $field->columnType($this->grammar);
        if ($withDefault && $field->hasDefault() && !$field->default instanceof \Closure) {
            $sql .= ' DEFAULT ' . $this->grammar->literal($field->toDb($field->default));
        }
        $sql .= $field->null ? ' NULL' : ' NOT NULL';

        return $withPrimaryKey && $field->primaryKey ? $sql . ' PRIMARY KEY' : $sql;
    }

    /**
     * @param array<string, Field> $fields
     */
    protected function createTableSql(string $table, ModelMeta $meta, array $fields): string
    {
        $definitions = [];
        foreach ($fields as $field) {
            $definitions[] = $this->columnDefinition($field);
        }
        foreach ($fields as $field) {
            if ($field->unique && !$field->primaryKey) {
                $definitions[] = $this->uniqueConstraint($meta->table, $field);
            }
        }
        foreach ($fields as $field) {
            if ($field instanceof ForeignKey) {
                $definitions[] = $this->foreignKeyConstraint($meta->table, $field);
            }
        }

        return sprintf('CREATE TABLE %s (%s)', $this->grammar->quoteIdentifier($table), implode(', ', $definitions));
    }

    /**
     * The indexes of single fields (dbIndex) and of Model::indexes().
     *
     * @param array<string, Field> $fields
     */
    protected function createIndexes(ModelMeta $meta, array $fields): void
    {
        foreach ($fields as $field) {
            if ($field->needsIndex()) {
                $this->execute($this->createIndexSql($meta->table, $this->grammar->indexName($meta->table, [$field->column], 'idx'), [$field->column], false));
            }
        }
        foreach ($meta->indexes as $index) {
            $columns = [];
            foreach ($index->fields as $name) {
                if (!isset($fields[$name])) {
                    continue 2;
                }
                $columns[] = $fields[$name]->column;
            }
            $this->execute($this->createIndexSql($meta->table, $index->nameFor($meta->table, $columns, $this->grammar), $columns, $index->unique));
        }
    }

    protected function uniqueConstraint(string $table, Field $field): string
    {
        return sprintf(
            'CONSTRAINT %s UNIQUE (%s)',
            $this->grammar->quoteIdentifier($this->grammar->indexName($table, [$field->column], 'uniq')),
            $this->grammar->quoteIdentifier($field->column),
        );
    }

    protected function foreignKeyConstraint(string $table, ForeignKey $field): string
    {
        $target = $field->targetMeta();

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s',
            $this->grammar->quoteIdentifier($this->grammar->indexName($table, [$field->column], 'fk')),
            $this->grammar->quoteIdentifier($field->column),
            $this->grammar->quoteIdentifier($target->table),
            $this->grammar->quoteIdentifier($target->pk->column),
            $this->grammar->deferrable(),
        );
    }

    /**
     * @param list<string> $columns
     */
    protected function createIndexSql(string $table, string $name, array $columns, bool $unique): string
    {
        return sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $this->grammar->quoteIdentifier($name),
            $this->grammar->quoteIdentifier($table),
            implode(', ', array_map($this->grammar->quoteIdentifier(...), $columns)),
        );
    }

    protected function dropIndexSql(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->grammar->quoteIdentifier($name);
    }

    protected function dropUniqueSql(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->grammar->quoteIdentifier($table), $this->grammar->quoteIdentifier($name));
    }

    protected function dropForeignKeySql(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->grammar->quoteIdentifier($table), $this->grammar->quoteIdentifier($name));
    }

    /**
     * Statements that change a column's type and/or nullability.
     *
     * @return list<string>
     */
    protected function alterColumnSql(string $table, Field $old, Field $new, bool $typeChanged, bool $nullChanged): array
    {
        $prefix = sprintf('ALTER TABLE %s ALTER COLUMN %s', $this->grammar->quoteIdentifier($table), $this->grammar->quoteIdentifier($new->column));
        $statements = [];
        if ($typeChanged) {
            $type = $new->columnType($this->grammar);
            $statements[] = sprintf('%s TYPE %s USING %s::%s', $prefix, $type, $this->grammar->quoteIdentifier($new->column), $type);
        }
        if ($nullChanged) {
            $statements[] = $prefix . ($new->null ? ' DROP NOT NULL' : ' SET NOT NULL');
        }

        return $statements;
    }

    protected function execute(string $sql): void
    {
        $this->collected[] = $sql;
        if (!$this->collectOnly) {
            $this->connection->statement($sql);
        }
    }

    protected function isCollectOnly(): bool
    {
        return $this->collectOnly;
    }

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    protected static function metaOf(string|ModelMeta $model): ModelMeta
    {
        if ($model instanceof ModelMeta) {
            return $model;
        }
        if (!is_subclass_of($model, Model::class)) {
            throw new FieldError(sprintf('"%s" is not a model class.', $model));
        }

        return $model::meta();
    }

    private static function assertAttached(Field $field): void
    {
        if ($field->name === '' || $field->column === '') {
            throw new FieldError(sprintf('The %s has no name. Use a field of the model, e.g. Post::meta()->field(\'title\').', $field::class));
        }
    }

    private static function foreignKeyTarget(Field $field): ?string
    {
        if (!$field instanceof ForeignKey) {
            return null;
        }
        $target = $field->targetMeta();

        return $target->table . '.' . $target->pk->column;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Schema;

use BrewPHP\Db\FieldError;
use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\IntegrityError;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;

/**
 * SQLite can add and rename columns, but most other changes need the
 * table to be rebuilt, like Django does:
 *
 * 1. create "new__<table>" with the new definition;
 * 2. copy the rows into it;
 * 3. drop the old table and rename the new one;
 * 4. create the indexes again.
 *
 * Foreign key checks are switched off during the rebuild and verified
 * afterwards with PRAGMA foreign_key_check.
 */
final class SqliteSchemaEditor extends SchemaEditor
{
    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function addField(string|ModelMeta $model, Field $field): void
    {
        $meta = self::metaOf($model);
        self::assertAttached($field);
        $hasStaticDefault = $field->hasDefault() && !$field->default instanceof \Closure;
        // ALTER TABLE ADD COLUMN cannot add a UNIQUE or PRIMARY KEY column.
        if (!$field->unique && !$field->primaryKey && ($field->null || $hasStaticDefault) && !($field instanceof ForeignKey && !$field->null)) {
            $sql = 'ALTER TABLE ' . $this->grammar->quoteIdentifier($meta->table) . ' ADD COLUMN ' . $this->columnDefinition($field, withDefault: true);
            if ($field instanceof ForeignKey) {
                $target = $field->targetMeta();
                $sql .= sprintf(' REFERENCES %s (%s)%s', $this->grammar->quoteIdentifier($target->table), $this->grammar->quoteIdentifier($target->pk->column), $this->grammar->deferrable());
            }
            $this->execute($sql);
            if ($field->needsIndex()) {
                $this->execute($this->createIndexSql($meta->table, $this->grammar->indexName($meta->table, [$field->column], 'idx'), [$field->column], false));
            }

            return;
        }

        // An empty table can get a NOT NULL column without a default, like on other databases.
        $isEmpty = !$this->isCollectOnly() && $this->connection->selectOne('SELECT 1 FROM ' . $this->grammar->quoteIdentifier($meta->table) . ' LIMIT 1') === null;
        if (!$field->null && !$hasStaticDefault && !$isEmpty) {
            throw new FieldError(sprintf(
                'Cannot add the non-nullable field "%s" to %s without a default: existing rows need a value. Give it a default or null: true.',
                $field->name,
                $meta->table,
            ));
        }
        $fields = self::without($meta->fields, $field->name) + [$field->name => $field];
        $mapping = $this->identityMapping($fields);
        $mapping[$field->column] = $hasStaticDefault ? $this->grammar->literal($field->toDb($field->default)) : 'NULL';
        $this->rebuild($meta, $fields, $mapping);
    }

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function removeField(string|ModelMeta $model, Field $field): void
    {
        $meta = self::metaOf($model);
        $fields = self::without($meta->fields, $field->name);
        $this->rebuild($meta, $fields, $this->identityMapping($fields));
    }

    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function alterField(string|ModelMeta $model, Field $old, Field $new): void
    {
        $meta = self::metaOf($model);
        $fields = [];
        $placed = false;
        foreach ($meta->fields as $name => $field) {
            if ($name === $old->name || $name === $new->name) {
                if (!$placed) {
                    $fields[$new->name] = $new;
                    $placed = true;
                }
                continue;
            }
            $fields[$name] = $field;
        }
        if (!$placed) {
            $fields[$new->name] = $new;
        }

        $mapping = $this->identityMapping($fields);
        $source = $this->grammar->quoteIdentifier($old->column);
        // Without a default, copying fails only if a row really is NULL (like SET NOT NULL elsewhere).
        if ($old->null && !$new->null && $new->hasDefault() && !$new->default instanceof \Closure) {
            $source = sprintf('COALESCE(%s, %s)', $source, $this->grammar->literal($new->toDb($new->default)));
        }
        $mapping[$new->column] = $source;
        $this->rebuild($meta, $fields, $mapping);
    }

    /**
     * Rebuild the table with new fields.
     *
     * @param array<string, Field> $fields the new fields
     * @param array<string, string> $mapping new column => SQL expression on the old table
     */
    private function rebuild(ModelMeta $meta, array $fields, array $mapping): void
    {
        $temporary = 'new__' . $meta->table;
        $steps = function () use ($meta, $fields, $mapping, $temporary): void {
            $quote = $this->grammar->quoteIdentifier(...);
            $this->execute($this->createTableSql($temporary, $meta, $fields));
            $this->execute(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $quote($temporary),
                implode(', ', array_map($quote, array_keys($mapping))),
                implode(', ', $mapping),
                $quote($meta->table),
            ));
            $this->execute('DROP TABLE ' . $quote($meta->table));
            $this->execute(sprintf('ALTER TABLE %s RENAME TO %s', $quote($temporary), $quote($meta->table)));
            $this->createIndexes($meta, $fields);
        };

        if ($this->isCollectOnly()) {
            $steps();

            return;
        }

        $checksOn = (int) $this->connection->scalar('PRAGMA foreign_keys') === 1;
        if ($checksOn && $this->connection->inTransaction()) {
            throw new \LogicException(
                'SQLite cannot rebuild a table inside a transaction while foreign key checks are on. '
                . 'Run "PRAGMA foreign_keys = OFF" before starting the transaction.',
            );
        }
        if ($checksOn) {
            $this->connection->statement('PRAGMA foreign_keys = OFF');
        }
        try {
            $this->connection->transaction(function () use ($steps, $meta): void {
                $steps();
                $violations = $this->connection->select('PRAGMA foreign_key_check(' . $this->grammar->quoteIdentifier($meta->table) . ')');
                if ($violations !== []) {
                    throw new IntegrityError('PRAGMA foreign_key_check', [], new \PDOException(sprintf(
                        'Rebuilding %s broke %d foreign key reference(s).',
                        $meta->table,
                        count($violations),
                    ), 23000));
                }
            });
        } finally {
            if ($checksOn) {
                $this->connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * @param array<string, Field> $fields
     * @return array<string, Field>
     */
    private static function without(array $fields, string $name): array
    {
        unset($fields[$name]);

        return $fields;
    }

    /**
     * Copy each column as is.
     *
     * @param array<string, Field> $fields
     * @return array<string, string>
     */
    private function identityMapping(array $fields): array
    {
        $mapping = [];
        foreach ($fields as $field) {
            $mapping[$field->column] = $this->grammar->quoteIdentifier($field->column);
        }

        return $mapping;
    }
}

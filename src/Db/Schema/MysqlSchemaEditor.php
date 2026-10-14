<?php

declare(strict_types=1);

namespace BrewPHP\Db\Schema;

use BrewPHP\Db\Fields\Field;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;

/**
 * MySQL: indexes and foreign keys are dropped with their own syntax, a
 * column is changed with MODIFY, and a foreign key must be dropped before
 * its column.
 */
final class MysqlSchemaEditor extends SchemaEditor
{
    /**
     * @param class-string<Model>|ModelMeta $model
     */
    public function removeField(string|ModelMeta $model, Field $field): void
    {
        if ($field instanceof ForeignKey) {
            $table = self::metaOf($model)->table;
            $this->execute($this->dropForeignKeySql($table, $this->grammar->indexName($table, [$field->column], 'fk')));
        }
        parent::removeField($model, $field);
    }

    public function renameModel(string $oldTable, string $newTable): void
    {
        $this->execute(sprintf('RENAME TABLE %s TO %s', $this->grammar->quoteIdentifier($oldTable), $this->grammar->quoteIdentifier($newTable)));
    }

    protected function dropIndexSql(string $table, string $name): string
    {
        return sprintf('DROP INDEX %s ON %s', $this->grammar->quoteIdentifier($name), $this->grammar->quoteIdentifier($table));
    }

    protected function dropUniqueSql(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP INDEX %s', $this->grammar->quoteIdentifier($table), $this->grammar->quoteIdentifier($name));
    }

    protected function dropForeignKeySql(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $this->grammar->quoteIdentifier($table), $this->grammar->quoteIdentifier($name));
    }

    protected function alterColumnSql(string $table, Field $old, Field $new, bool $typeChanged, bool $nullChanged): array
    {
        return [sprintf('ALTER TABLE %s MODIFY %s', $this->grammar->quoteIdentifier($table), $this->columnDefinition($new, withPrimaryKey: false))];
    }
}

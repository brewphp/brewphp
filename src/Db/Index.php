<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Db\Grammars\Grammar;

/**
 * An index on one or more fields, declared in `Model::indexes()`:
 *
 *     public static function indexes(): array
 *     {
 *         return [
 *             new Index(['author', 'created_at']),
 *             new Index(['author', 'slug'], unique: true),  // like Django's unique_together
 *         ];
 *     }
 *
 * Without a name, a stable one is generated from the table and columns.
 */
final class Index
{
    /**
     * @param list<string> $fields field names
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $name = null,
        public readonly bool $unique = false,
    ) {
        if ($fields === []) {
            throw new \InvalidArgumentException('An index needs at least one field.');
        }
    }

    /**
     * The database columns of the index.
     *
     * @return list<string>
     */
    public function columns(ModelMeta $meta): array
    {
        return array_map(static fn (string $field): string => $meta->field($field)->column, $this->fields);
    }

    /**
     * The index name: the given one, or a stable name built from the
     * table and columns.
     *
     * @param list<string> $columns
     */
    public function nameFor(string $table, array $columns, Grammar $grammar): string
    {
        return $this->name ?? $grammar->indexName($table, $columns, $this->unique ? 'uniq' : 'idx');
    }
}

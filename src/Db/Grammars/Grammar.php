<?php

declare(strict_types=1);

namespace BrewPHP\Db\Grammars;

/**
 * Everything that differs in SQL between database engines: identifier
 * quoting, column types, LIMIT/OFFSET, LIKE and date functions.
 *
 * The query compiler and the schema editor only produce SQL through a
 * Grammar, so supporting another engine means writing one more subclass.
 */
abstract class Grammar
{
    /**
     * Column types by kind; "%d" placeholders are filled with the
     * parameters given to type().
     *
     * @var array<string, string>
     */
    protected array $types = [
        'varchar' => 'varchar(%d)',
        'text' => 'text',
        'integer' => 'integer',
        'bigint' => 'bigint',
        'float' => 'double precision',
        'decimal' => 'numeric(%d, %d)',
        'boolean' => 'boolean',
        'date' => 'date',
        'datetime' => 'timestamp',
        'time' => 'time',
        'json' => 'text',
        'uuid' => 'char(36)',
    ];

    /** The engine name used in the DATABASES setting: sqlite, mysql or pgsql. */
    abstract public function name(): string;

    /**
     * The column definition (after the column name) of an auto-increment
     * primary key.
     */
    abstract public function autoIncrementColumn(bool $big): string;

    /**
     * A database column type, e.g. `type('varchar', 200)` gives "varchar(200)".
     */
    public function type(string $kind, int ...$parameters): string
    {
        if (!isset($this->types[$kind])) {
            throw new \InvalidArgumentException(sprintf('Unknown column type "%s".', $kind));
        }

        return $parameters === [] ? $this->types[$kind] : vsprintf($this->types[$kind], $parameters);
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * `"table"."column"`.
     */
    public function quoteColumn(string $table, string $column): string
    {
        return $this->quoteIdentifier($table) . '.' . $this->quoteIdentifier($column);
    }

    /**
     * A value written into SQL (used for column defaults in DDL, where
     * placeholders are not allowed). Use bindings everywhere else.
     */
    public function literal(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            default => $this->quoteString(is_scalar($value) || $value instanceof \Stringable ? (string) $value : (string) json_encode($value)),
        };
    }

    /**
     * " LIMIT 10 OFFSET 20", or '' when there is neither.
     */
    public function compileLimit(?int $limit, int $offset): string
    {
        $sql = $limit !== null ? ' LIMIT ' . max(0, $limit) : '';

        return $offset > 0 ? $sql . ' OFFSET ' . $offset : $sql;
    }

    /**
     * A LIKE condition for the exact/contains/startswith/endswith text lookups.
     *
     * @param 'contains'|'startswith'|'endswith'|'exact' $kind
     * @return array{0: string, 1: string} the SQL with one placeholder, and its binding
     */
    public function compileLike(string $column, string $kind, bool $caseInsensitive, string $value): array
    {
        return [
            $column . ($caseInsensitive ? ' ILIKE ?' : ' LIKE ?'),
            $this->likePattern($this->escapeLike($value), $kind),
        ];
    }

    /**
     * Extract a part of a date as an integer: `year`, `month` or `day`.
     */
    public function datePart(string $part, string $column): string
    {
        if (!in_array($part, ['year', 'month', 'day'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown date part "%s".', $part));
        }

        return sprintf('EXTRACT(%s FROM %s)', strtoupper($part), $column);
    }

    /**
     * An INSERT statement for one row. Without columns, the row gets only
     * default values.
     *
     * @param list<string> $columns
     */
    public function compileInsert(string $table, array $columns, ?string $returning = null): string
    {
        $sql = $columns === []
            ? 'INSERT INTO ' . $this->quoteIdentifier($table) . ' DEFAULT VALUES'
            : sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->quoteIdentifier($table),
                implode(', ', array_map($this->quoteIdentifier(...), $columns)),
                implode(', ', array_fill(0, count($columns), '?')),
            );

        return $returning !== null && $this->supportsReturning() ? $sql . ' RETURNING ' . $this->quoteIdentifier($returning) : $sql;
    }

    /**
     * Whether INSERT ... RETURNING is used to read a new primary key.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * Whether CREATE/ALTER TABLE can be rolled back inside a transaction.
     */
    public function supportsDdlTransactions(): bool
    {
        return true;
    }

    /**
     * Added after FOREIGN KEY constraints so related rows can be changed
     * in any order inside a transaction.
     */
    public function deferrable(): string
    {
        return ' DEFERRABLE INITIALLY DEFERRED';
    }

    /**
     * The longest identifier the engine accepts.
     */
    public function maxNameLength(): int
    {
        return 63;
    }

    /**
     * A deterministic, length-safe name for an index or a constraint, such
     * as "blog_post_author_id_6c1f3a2b_fk". The same input always gives the
     * same name, so the schema editor can find it again to drop it.
     *
     * @param list<string> $columns
     */
    public function indexName(string $table, array $columns, string $suffix): string
    {
        $hash = substr(md5($table . '|' . implode('|', $columns) . '|' . $suffix), 0, 8);
        $base = substr($table . '_' . implode('_', $columns), 0, $this->maxNameLength() - strlen($hash) - strlen($suffix) - 2);

        return $base . '_' . $hash . '_' . $suffix;
    }

    /**
     * Escape the LIKE wildcards % and _ (and the escape character itself).
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    protected function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function likePattern(string $escaped, string $kind): string
    {
        return match ($kind) {
            'contains' => '%' . $escaped . '%',
            'startswith' => $escaped . '%',
            'endswith' => '%' . $escaped,
            default => $escaped,
        };
    }
}

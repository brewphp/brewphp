<?php

declare(strict_types=1);

namespace BrewPHP\Db\Grammars;

/**
 * MySQL and MariaDB. Identifiers are quoted with backticks. Text matching
 * follows the column collation (usually case-insensitive), so the
 * case-sensitive lookups use LIKE BINARY.
 */
class MysqlGrammar extends Grammar
{
    public function __construct()
    {
        $this->types = array_replace($this->types, [
            'text' => 'longtext',
            'decimal' => 'decimal(%d, %d)',
            'boolean' => 'bool',
            'datetime' => 'datetime',
            'json' => 'json',
        ]);
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function autoIncrementColumn(bool $big): string
    {
        return ($big ? 'bigint' : 'integer') . ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function literal(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : parent::literal($value);
    }

    public function compileLimit(?int $limit, int $offset): string
    {
        // MySQL needs a LIMIT before OFFSET: use the largest possible one.
        return $limit === null && $offset > 0 ? ' LIMIT 18446744073709551615 OFFSET ' . $offset : parent::compileLimit($limit, $offset);
    }

    public function compileLike(string $column, string $kind, bool $caseInsensitive, string $value): array
    {
        return [
            $column . ($caseInsensitive ? ' LIKE ?' : ' LIKE BINARY ?'),
            $this->likePattern($this->escapeLike($value), $kind),
        ];
    }

    public function datePart(string $part, string $column): string
    {
        if (!in_array($part, ['year', 'month', 'day'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown date part "%s".', $part));
        }

        return strtoupper($part) . '(' . $column . ')';
    }

    public function compileInsert(string $table, array $columns, ?string $returning = null): string
    {
        return $columns === [] ? 'INSERT INTO ' . $this->quoteIdentifier($table) . ' () VALUES ()' : parent::compileInsert($table, $columns);
    }

    public function supportsDdlTransactions(): bool
    {
        return false;
    }

    public function deferrable(): string
    {
        return '';
    }

    public function maxNameLength(): int
    {
        return 64;
    }

    protected function quoteString(string $value): string
    {
        // MySQL treats a backslash in a string literal as an escape character.
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db\Grammars;

/**
 * SQLite. Case-sensitive text matching uses GLOB, because SQLite's LIKE
 * ignores the case of ASCII letters.
 */
class SqliteGrammar extends Grammar
{
    public function __construct()
    {
        $this->types = array_replace($this->types, [
            'float' => 'real',
            'decimal' => 'decimal(%d, %d)',
            'boolean' => 'bool',
            'datetime' => 'datetime',
        ]);
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function autoIncrementColumn(bool $big): string
    {
        // SQLite only auto-increments a column declared exactly "integer".
        return 'integer NOT NULL PRIMARY KEY AUTOINCREMENT';
    }

    public function literal(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : parent::literal($value);
    }

    public function compileTableExists(): string
    {
        return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    public function compileLimit(?int $limit, int $offset): string
    {
        // SQLite needs a LIMIT before OFFSET; -1 means "no limit".
        return $limit === null && $offset > 0 ? ' LIMIT -1 OFFSET ' . $offset : parent::compileLimit($limit, $offset);
    }

    public function compileLike(string $column, string $kind, bool $caseInsensitive, string $value): array
    {
        if ($caseInsensitive) {
            return [$column . " LIKE ? ESCAPE '\\'", $this->likePattern($this->escapeLike($value), $kind)];
        }
        // GLOB wildcards are * and ?; a [x] character class matches them literally.
        $escaped = strtr($value, ['[' => '[[]', '*' => '[*]', '?' => '[?]']);
        $pattern = match ($kind) {
            'contains' => '*' . $escaped . '*',
            'startswith' => $escaped . '*',
            'endswith' => '*' . $escaped,
            default => $escaped,
        };

        return [$column . ' GLOB ?', $pattern];
    }

    public function datePart(string $part, string $column): string
    {
        $format = match ($part) {
            'year' => '%Y',
            'month' => '%m',
            'day' => '%d',
            default => throw new \InvalidArgumentException(sprintf('Unknown date part "%s".', $part)),
        };

        return sprintf("CAST(strftime('%s', %s) AS integer)", $format, $column);
    }
}

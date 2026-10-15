<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Db\Connection;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\ModelMeta;

/**
 * Keeps track of applied migrations in the `brewphp_migrations` table.
 */
final class Recorder
{
    public const TABLE = 'brewphp_migrations';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function hasTable(): bool
    {
        return $this->connection->hasTable(self::TABLE);
    }

    public function ensureTable(): void
    {
        if (!$this->hasTable()) {
            $this->connection->schemaEditor()->createModel(self::meta());
        }
    }

    /**
     * Applied migrations, as "app.name" => true.
     *
     * @return array<string, true>
     */
    public function applied(): array
    {
        if (!$this->hasTable()) {
            return [];
        }
        $grammar = $this->connection->grammar();
        $applied = [];
        $rows = $this->connection->select(sprintf(
            'SELECT %s, %s FROM %s',
            $grammar->quoteIdentifier('app'),
            $grammar->quoteIdentifier('name'),
            $grammar->quoteIdentifier(self::TABLE),
        ));
        foreach ($rows as $row) {
            $applied[$row['app'] . '.' . $row['name']] = true;
        }

        return $applied;
    }

    public function record(string $app, string $name): void
    {
        $this->ensureTable();
        $this->connection->insert(self::TABLE, ['app' => $app, 'name' => $name, 'applied' => gmdate('Y-m-d H:i:s')]);
    }

    public function unrecord(string $app, string $name): void
    {
        $grammar = $this->connection->grammar();
        $this->connection->statement(
            sprintf('DELETE FROM %s WHERE %s = ? AND %s = ?', $grammar->quoteIdentifier(self::TABLE), $grammar->quoteIdentifier('app'), $grammar->quoteIdentifier('name')),
            [$app, $name],
        );
    }

    private static function meta(): ModelMeta
    {
        return new ModelMeta('', self::TABLE, [
            'app' => new CharField(maxLength: 255),
            'name' => new CharField(maxLength: 255),
            'applied' => new DateTimeField(),
        ], 'brewphp', 'Migration');
    }
}

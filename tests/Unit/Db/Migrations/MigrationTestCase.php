<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db\Migrations;

use BrewPHP\Core\Application;
use BrewPHP\Db\Connection;
use BrewPHP\Db\Migrations\Executor;
use BrewPHP\Db\Migrations\Graph;
use BrewPHP\Db\Migrations\Migration;
use BrewPHP\Db\Migrations\Operations\Operation;
use BrewPHP\Db\Migrations\Recorder;
use BrewPHP\Tests\TestCase;

/**
 * Boots the fixture project on an empty in-memory SQLite database.
 */
abstract class MigrationTestCase extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        $this->app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']]]);
    }

    protected function db(): Connection
    {
        return $this->app->db();
    }

    /**
     * A migration built in code instead of loaded from a file.
     *
     * @param list<Operation> $operations
     * @param list<string> $dependencies
     */
    protected static function migration(string $app, string $name, array $operations, array $dependencies = []): Migration
    {
        $migration = new class ($operations) extends Migration {
            /**
             * @param list<Operation> $ops
             */
            public function __construct(private readonly array $ops)
            {
            }

            public function operations(): array
            {
                return $this->ops;
            }
        };
        $migration->app = $app;
        $migration->name = $name;
        $migration->dependencies = $dependencies;

        return $migration;
    }

    /**
     * @param list<Migration> $migrations
     */
    protected function executor(array $migrations): Executor
    {
        return new Executor($this->db(), new Graph($migrations), new Recorder($this->db()));
    }

    /**
     * @return list<string>
     */
    protected function columns(string $table): array
    {
        return array_map(static fn (array $row): string => (string) $row['name'], $this->db()->select('PRAGMA table_info(' . $table . ')'));
    }

    /**
     * @return list<string>
     */
    protected function indexes(string $table): array
    {
        $names = array_map(static fn (array $row): string => (string) $row['name'], $this->db()->select('PRAGMA index_list(' . $table . ')'));
        $names = array_values(array_filter($names, static fn (string $name): bool => !str_starts_with($name, 'sqlite_autoindex')));
        sort($names);

        return $names;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Db\Connection;
use BrewPHP\Db\ConnectionManager;
use BrewPHP\Db\IntegrityError;
use BrewPHP\Db\QueryException;
use BrewPHP\Tests\Fixtures\Models\PostStatus;
use BrewPHP\Tests\TestCase;

final class ConnectionTest extends TestCase
{
    private function memory(): Connection
    {
        $connection = new Connection('default', ['ENGINE' => 'sqlite', 'NAME' => ':memory:']);
        $connection->statement('CREATE TABLE t (id integer PRIMARY KEY AUTOINCREMENT, name varchar(20) UNIQUE, flag bool, at datetime)');

        return $connection;
    }

    public function testEnginesAndAliases(): void
    {
        self::assertSame('sqlite', Connection::engineOf(['ENGINE' => 'sqlite3']));
        self::assertSame('sqlite', Connection::engineOf([]));
        self::assertSame('mysql', Connection::engineOf(['ENGINE' => 'MariaDB']));
        self::assertSame('pgsql', Connection::engineOf(['ENGINE' => 'postgresql']));
        self::assertSame('pgsql', (new Connection('x', ['ENGINE' => 'postgres']))->engine());

        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('Unknown database ENGINE "oracle"');
        Connection::engineOf(['ENGINE' => 'oracle']);
    }

    public function testDsn(): void
    {
        self::assertSame('sqlite::memory:', (new Connection('x', ['ENGINE' => 'sqlite', 'NAME' => '']))->dsn());
        self::assertSame('sqlite:/tmp/db.sqlite3', (new Connection('x', ['ENGINE' => 'sqlite', 'NAME' => '/tmp/db.sqlite3']))->dsn());
        self::assertSame(
            'mysql:host=db.local;port=3307;dbname=shop;charset=utf8mb4;unix_socket=/run/mysqld.sock',
            (new Connection('x', ['ENGINE' => 'mysql', 'NAME' => 'shop', 'HOST' => 'db.local', 'PORT' => 3307, 'OPTIONS' => ['unix_socket' => '/run/mysqld.sock', \PDO::ATTR_TIMEOUT => 5]]))->dsn(),
        );
        self::assertSame(
            'pgsql:host=127.0.0.1;port=5432;dbname=shop;sslmode=require',
            (new Connection('x', ['ENGINE' => 'pgsql', 'NAME' => 'shop', 'OPTIONS' => ['sslmode' => 'require']]))->dsn(),
        );
    }

    public function testConnectsLazily(): void
    {
        $connection = new Connection('x', ['ENGINE' => 'pgsql', 'NAME' => 'nowhere', 'HOST' => '127.0.0.1', 'PORT' => 1]);

        self::assertFalse($connection->isConnected());
        self::assertSame('pgsql', $connection->grammar()->name());
        self::assertSame('nowhere', $connection->config('NAME'));
    }

    public function testFailedConnectionIsReported(): void
    {
        $connection = new Connection('x', ['ENGINE' => 'sqlite', 'NAME' => sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)) . '/db.sqlite3']);

        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('does not exist');
        $connection->pdo();
    }

    public function testQueries(): void
    {
        $db = $this->memory();
        self::assertTrue($db->isConnected());

        self::assertSame(1, $db->insert('t', ['name' => 'a', 'flag' => true, 'at' => new \DateTimeImmutable('2026-01-02 03:04:05')]));
        self::assertSame(2, $db->insert('t', ['name' => PostStatus::Draft, 'flag' => false]));
        self::assertSame(3, $db->insert('t', []));
        self::assertNull($db->insert('t', ['name' => 'x'], primaryKey: null));

        self::assertSame(['id' => 1, 'name' => 'a', 'flag' => 1, 'at' => '2026-01-02 03:04:05'], $db->selectOne('SELECT * FROM t WHERE id = ?', [1]));
        self::assertNull($db->selectOne('SELECT * FROM t WHERE id = ?', [99]));
        self::assertSame([['draft']], $db->selectList('SELECT name FROM t WHERE flag = ?', [false]));
        self::assertSame(4, $db->scalar('SELECT COUNT(*) FROM t'));
        self::assertNull($db->scalar('SELECT name FROM t WHERE id = 99'));
        self::assertSame(2, $db->statement('UPDATE t SET flag = ? WHERE id IN (?, ?)', [true, 2, 3]));
        self::assertCount(3, $db->select('SELECT * FROM t WHERE flag = ?', [true]));
        self::assertSame("'it''s'", $db->quote("it's"));
        self::assertSame(['NULL', '5', '1'], [$db->quote(null), $db->quote(5), $db->quote(true)]);
    }

    public function testErrorsCarryTheSql(): void
    {
        $db = $this->memory();
        try {
            $db->select('SELECT nope FROM t');
            self::fail('QueryException expected');
        } catch (QueryException $e) {
            self::assertSame('SELECT nope FROM t', $e->sql);
            self::assertStringContainsString('(SQL: SELECT nope FROM t)', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            self::assertSame('HY000', $e->sqlState());
        }

        $db->insert('t', ['name' => 'a']);
        $this->expectException(IntegrityError::class);
        $db->insert('t', ['name' => 'a']);
    }

    public function testTransactions(): void
    {
        $db = $this->memory();

        $result = $db->transaction(function (Connection $db): string {
            $db->insert('t', ['name' => 'kept']);

            return 'done';
        });
        self::assertSame('done', $result);

        try {
            $db->transaction(function (Connection $db): void {
                $db->insert('t', ['name' => 'lost']);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        // A failing inner transaction only rolls back to its savepoint.
        $db->transaction(function (Connection $db): void {
            $db->insert('t', ['name' => 'outer']);
            self::assertSame(1, $db->transactionLevel());
            try {
                $db->transaction(function (Connection $db): void {
                    self::assertSame(2, $db->transactionLevel());
                    $db->insert('t', ['name' => 'inner']);

                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
            }
        });

        self::assertSame(['kept', 'outer'], array_column($db->select('SELECT name FROM t ORDER BY id'), 'name'));
        self::assertFalse($db->inTransaction());

        $db->beginTransaction();
        $db->insert('t', ['name' => 'manual']);
        $db->rollBack();
        self::assertSame(2, $db->scalar('SELECT COUNT(*) FROM t'));
    }

    public function testCommitWithoutTransactionFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->memory()->commit();
    }

    public function testRollbackWithoutTransactionFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->memory()->rollBack();
    }

    public function testQueryLog(): void
    {
        $db = $this->memory();
        self::assertSame([], $db->lastQueries());

        $db->enableQueryLog();
        $db->select('SELECT * FROM t WHERE id = ?', [1]);
        $log = $db->lastQueries();
        self::assertCount(1, $log);
        self::assertSame('SELECT * FROM t WHERE id = ?', $log[0]['sql']);
        self::assertSame([1], $log[0]['bindings']);
        self::assertIsFloat($log[0]['time']);

        $db->flushQueryLog();
        self::assertSame([], $db->lastQueries());
        $db->enableQueryLog(false);
        $db->select('SELECT 1');
        self::assertSame([], $db->lastQueries());
    }

    public function testDisconnect(): void
    {
        $db = new Connection('x', ['ENGINE' => 'sqlite', 'NAME' => ':memory:']);
        $db->pdo();
        $db->disconnect();
        self::assertFalse($db->isConnected());
    }

    public function testUsesAGivenPdo(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $db = new Connection('x', ['ENGINE' => 'sqlite'], $pdo);

        self::assertSame($pdo, $db->pdo());
        self::assertSame(1, $db->scalar('PRAGMA foreign_keys'));
    }

    public function testManager(): void
    {
        $manager = new ConnectionManager([
            'default' => ['ENGINE' => 'sqlite', 'NAME' => 'storage/db.sqlite3'],
            'memory' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:'],
            'shop' => ['ENGINE' => 'mysql', 'NAME' => 'shop'],
        ], '/srv/app', logQueries: true);

        self::assertSame('/srv/app' . DIRECTORY_SEPARATOR . 'storage/db.sqlite3', $manager->config()['NAME']);
        self::assertSame(':memory:', $manager->config('memory')['NAME']);
        self::assertSame(3306, $manager->config('shop')['PORT']);
        self::assertSame('127.0.0.1', $manager->config('shop')['HOST']);
        self::assertSame(['default', 'memory', 'shop'], $manager->names());
        self::assertSame($manager->connection('memory'), $manager->connection('memory'));
        self::assertTrue($manager->has('shop'));
        self::assertFalse($manager->has('nope'));

        $memory = $manager->connection('memory');
        $memory->select('SELECT 1');
        self::assertCount(1, $memory->lastQueries(), 'DEBUG turns the query log on');
        $manager->disconnect();
        self::assertFalse($memory->isConnected());

        $custom = new Connection('custom', ['ENGINE' => 'sqlite']);
        $manager->set('custom', $custom);
        self::assertSame($custom, $manager->connection('custom'));

        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('The database "nope" is not configured');
        $manager->connection('nope');
    }

    public function testApplicationAndHelper(): void
    {
        $app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']]]);

        self::assertSame($app->db(), db());
        self::assertSame('sqlite', db()->engine());
        self::assertSame(['default' => ['ENGINE' => 'sqlite', 'NAME' => 'storage/db.sqlite3']], (new \BrewPHP\Core\Settings())->get('DATABASES'));
    }
}

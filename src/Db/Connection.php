<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Grammars\MysqlGrammar;
use BrewPHP\Db\Grammars\PostgresGrammar;
use BrewPHP\Db\Grammars\SqliteGrammar;
use BrewPHP\Db\Schema\MysqlSchemaEditor;
use BrewPHP\Db\Schema\PostgresSchemaEditor;
use BrewPHP\Db\Schema\SchemaEditor;
use BrewPHP\Db\Schema\SqliteSchemaEditor;

/**
 * One database connection: a thin layer over PDO that connects on first
 * use, binds values with the right types, wraps errors in QueryException
 * and supports nested transactions.
 *
 *     $rows = db()->select('SELECT * FROM blog_post WHERE views > ?', [10]);
 *     $id = db()->insert('blog_post', ['title' => 'Hello']);
 *     db()->transaction(function () { ... });
 *
 * `$config` is one entry of the DATABASES setting.
 */
final class Connection
{
    /** How many queries the query log keeps (the oldest are dropped). */
    public const LOG_LIMIT = 500;

    private ?\PDO $pdo;

    private readonly Grammar $grammar;

    private int $transactionLevel = 0;

    /** @var list<array{sql: string, bindings: list<mixed>, time: float}> */
    private array $queryLog = [];

    /**
     * @param array<string, mixed> $config ENGINE, NAME, HOST, PORT, USER, PASSWORD, OPTIONS
     * @param \PDO|null $pdo an existing PDO object to use instead of connecting
     */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
        ?\PDO $pdo = null,
        private bool $logging = false,
    ) {
        $this->grammar = match (self::engineOf($config)) {
            'sqlite' => new SqliteGrammar(),
            'mysql' => new MysqlGrammar(),
            'pgsql' => new PostgresGrammar(),
        };
        $this->pdo = $pdo;
        if ($pdo !== null) {
            $this->configure($pdo);
        }
    }

    /**
     * The normalised engine name of a DATABASES entry: sqlite, mysql or pgsql.
     * Django-style names such as "sqlite3" and "postgresql" are accepted.
     *
     * @param array<string, mixed> $config
     * @return 'sqlite'|'mysql'|'pgsql'
     */
    public static function engineOf(array $config): string
    {
        $engine = strtolower(is_string($config['ENGINE'] ?? null) ? $config['ENGINE'] : 'sqlite');

        return match ($engine) {
            'sqlite', 'sqlite3' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            default => throw new ImproperlyConfigured(sprintf(
                'Unknown database ENGINE "%s". Use "sqlite", "mysql" or "pgsql".',
                $engine,
            )),
        };
    }

    public function engine(): string
    {
        return $this->grammar->name();
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * A setting of this connection, e.g. `config('NAME')`.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * The PDO object, connecting first if needed.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Close the connection; the next query opens a new one.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }

    /**
     * The DSN used to connect, built from ENGINE, NAME, HOST and PORT.
     * String keys of OPTIONS are added to it (e.g. `'charset' => 'utf8mb4'`).
     */
    public function dsn(): string
    {
        $engine = $this->engine();
        $name = (string) ($this->config['NAME'] ?? '');
        if ($engine === 'sqlite') {
            return 'sqlite:' . ($name === '' ? ':memory:' : $name);
        }

        $parts = [
            'host' => (string) ($this->config['HOST'] ?? '127.0.0.1'),
            'port' => (string) ($this->config['PORT'] ?? ($engine === 'mysql' ? 3306 : 5432)),
            'dbname' => $name,
        ];
        if ($engine === 'mysql') {
            $parts['charset'] = 'utf8mb4';
        }
        foreach ($this->options() as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $parts[$key] = (string) $value;
            }
        }
        $pairs = [];
        foreach ($parts as $key => $value) {
            if ($value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }

        return $engine . ':' . implode(';', $pairs);
    }

    // Queries -----------------------------------------------------------------

    /**
     * Run a SELECT and return every row as an associative array.
     *
     * @param list<mixed> $bindings values for the ? placeholders
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Like select(), with rows as lists of values in column order.
     *
     * @param list<mixed> $bindings
     * @return list<list<mixed>>
     */
    public function selectList(string $sql, array $bindings = []): array
    {
        /** @var list<list<mixed>> */
        return $this->run($sql, $bindings)->fetchAll(\PDO::FETCH_NUM);
    }

    /**
     * The first row of a SELECT, or null.
     *
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch(\PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    /**
     * The first column of the first row, e.g. `scalar('SELECT COUNT(*) FROM blog_post')`.
     *
     * @param list<mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Run an INSERT, UPDATE, DELETE or DDL statement; returns the number
     * of affected rows.
     *
     * @param list<mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Insert one row and return its new primary key (or null when the
     * table has no auto-increment key).
     *
     *     $id = db()->insert('blog_post', ['title' => 'Hello', 'views' => 0]);
     *
     * @param array<string, mixed> $values column => value
     */
    public function insert(string $table, array $values, ?string $primaryKey = 'id'): int|string|null
    {
        $sql = $this->grammar->compileInsert($table, array_keys($values), $primaryKey);
        $statement = $this->run($sql, array_values($values));
        if ($primaryKey === null) {
            return null;
        }
        $id = $this->grammar->supportsReturning() ? $statement->fetchColumn() : $this->pdo()->lastInsertId();
        if ($id === false || $id === null || $id === '' || $id === '0') {
            return null;
        }

        return is_numeric($id) && (string) (int) $id === (string) $id ? (int) $id : (string) $id;
    }

    /**
     * Quote a value for use in SQL. Prefer bindings (? placeholders).
     */
    public function quote(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value), is_int($value), is_float($value) => $this->grammar->literal($value),
            default => $this->pdo()->quote((string) self::prepareValue($value)),
        };
    }

    // Transactions ------------------------------------------------------------

    /**
     * Run a callback in a transaction: committed when it returns, rolled
     * back when it throws. Nested calls use savepoints, so an inner failure
     * only undoes the inner work.
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
        $this->commit();

        return $result;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT ' . $this->savepoint());
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('There is no active transaction to commit.');
        }
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            // MySQL commits implicitly after DDL, so PDO may have no transaction left.
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->commit();
            }
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint());
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('There is no active transaction to roll back.');
        }
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint());
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * How many transactions are open (1 = a transaction, 2+ = savepoints).
     */
    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    // Schema ------------------------------------------------------------------

    /**
     * Whether a table exists in the database.
     */
    public function hasTable(string $table): bool
    {
        return $this->selectOne($this->grammar->compileTableExists(), [$table]) !== null;
    }

    /**
     * A schema editor to create and change tables. With `$collectOnly`, the
     * SQL is collected (see `collectedSql()`) instead of executed.
     */
    public function schemaEditor(bool $collectOnly = false): SchemaEditor
    {
        return match ($this->engine()) {
            'sqlite' => new SqliteSchemaEditor($this, $collectOnly),
            'mysql' => new MysqlSchemaEditor($this, $collectOnly),
            default => new PostgresSchemaEditor($this, $collectOnly),
        };
    }

    // Query log ---------------------------------------------------------------

    /**
     * Record every query (on by default when DEBUG is true).
     */
    public function enableQueryLog(bool $enabled = true): void
    {
        $this->logging = $enabled;
    }

    /**
     * The queries run since logging started, oldest first: SQL, bindings
     * and duration in milliseconds.
     *
     * @return list<array{sql: string, bindings: list<mixed>, time: float}>
     */
    public function lastQueries(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    // Internals ---------------------------------------------------------------

    /**
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings): \PDOStatement
    {
        $start = microtime(true);
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach (array_values($bindings) as $i => $value) {
                $value = self::prepareValue($value);
                $statement->bindValue($i + 1, $value, match (true) {
                    $value === null => \PDO::PARAM_NULL,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    is_int($value) => \PDO::PARAM_INT,
                    default => \PDO::PARAM_STR,
                });
            }
            $statement->execute();
        } catch (\PDOException $e) {
            throw str_starts_with((string) $e->getCode(), '23')
                ? new IntegrityError($sql, $bindings, $e)
                : new QueryException($sql, $bindings, $e);
        }

        if ($this->logging) {
            $this->queryLog[] = ['sql' => $sql, 'bindings' => $bindings, 'time' => round((microtime(true) - $start) * 1000, 3)];
            if (count($this->queryLog) > self::LOG_LIMIT) {
                array_shift($this->queryLog);
            }
        }

        return $statement;
    }

    /**
     * Turn PHP values PDO cannot bind into scalars.
     */
    private static function prepareValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \Stringable => (string) $value,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            is_float($value) => (string) $value,
            default => $value,
        };
    }

    private function connect(): \PDO
    {
        $engine = $this->engine();
        if (!in_array($engine, \PDO::getAvailableDrivers(), true)) {
            throw new ImproperlyConfigured(sprintf(
                'The PDO driver "%s" is not installed. Enable the pdo_%s PHP extension.',
                $engine,
                $engine,
            ));
        }
        if ($engine === 'sqlite') {
            $file = (string) ($this->config['NAME'] ?? '');
            if ($file !== '' && $file !== ':memory:' && !is_dir(dirname($file))) {
                throw new ImproperlyConfigured(sprintf('The folder of the SQLite database "%s" does not exist.', $file));
            }
        }

        $attributes = [];
        foreach ($this->options() as $key => $value) {
            if (is_int($key)) {
                $attributes[$key] = $value;
            }
        }
        if ($engine === 'mysql') {
            // Real prepared statements return integers as PHP ints.
            $attributes[\PDO::ATTR_EMULATE_PREPARES] ??= false;
        }

        try {
            $pdo = new \PDO(
                $this->dsn(),
                $engine === 'sqlite' ? null : (string) ($this->config['USER'] ?? ''),
                $engine === 'sqlite' ? null : (string) ($this->config['PASSWORD'] ?? ''),
                $attributes,
            );
        } catch (\PDOException $e) {
            throw new QueryException('(connect to ' . $this->name . ')', [], $e);
        }
        $this->configure($pdo);

        return $pdo;
    }

    private function configure(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if ($this->engine() === 'sqlite') {
            // SQLite only enforces foreign keys when asked to.
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @return array<mixed>
     */
    private function options(): array
    {
        $options = $this->config['OPTIONS'] ?? [];

        return is_array($options) ? $options : [];
    }

    private function savepoint(): string
    {
        return 'brewphp_sp_' . $this->transactionLevel;
    }
}

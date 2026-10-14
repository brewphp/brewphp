<?php

declare(strict_types=1);

namespace BrewPHP\Db;

use BrewPHP\Core\ImproperlyConfigured;

/**
 * The named connections of the DATABASES setting, opened on first use.
 *
 *     db();          // the "default" connection
 *     db('legacy');  // another entry of DATABASES
 */
final class ConnectionManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * @param array<mixed> $databases the DATABASES setting
     * @param string $basePath project folder; relative SQLite paths start there
     */
    public function __construct(
        private readonly array $databases,
        private readonly string $basePath = '',
        private readonly bool $logQueries = false,
    ) {
    }

    /**
     * The settings of every connection, after defaults are applied.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function withDefaults(array $config): array
    {
        $engine = Connection::engineOf($config);

        return array_replace([
            'ENGINE' => $engine,
            'NAME' => $engine === 'sqlite' ? 'storage/db.sqlite3' : '',
            'HOST' => '127.0.0.1',
            'PORT' => $engine === 'mysql' ? 3306 : ($engine === 'pgsql' ? 5432 : null),
            'USER' => '',
            'PASSWORD' => '',
            'OPTIONS' => [],
        ], $config, ['ENGINE' => $engine]);
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= 'default';

        return $this->connections[$name] ??= $this->make($name);
    }

    /**
     * Use a ready-made connection under a name (handy in tests).
     */
    public function set(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
    }

    public function has(string $name): bool
    {
        return isset($this->connections[$name]) || isset($this->databases[$name]);
    }

    /**
     * The settings of a connection, with defaults and an absolute SQLite path.
     *
     * @return array<string, mixed>
     */
    public function config(?string $name = null): array
    {
        $name ??= 'default';
        $config = $this->databases[$name] ?? null;
        if (!is_array($config)) {
            throw new ImproperlyConfigured(sprintf('The database "%s" is not configured in the DATABASES setting.', $name));
        }
        /** @var array<string, mixed> $config */
        $config = self::withDefaults($config);
        $file = (string) $config['NAME'];
        if ($config['ENGINE'] === 'sqlite' && $file !== ':memory:' && $file !== '' && $this->basePath !== '' && !self::isAbsolute($file)) {
            $config['NAME'] = $this->basePath . DIRECTORY_SEPARATOR . $file;
        }

        return $config;
    }

    /**
     * The names of the configured connections.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_merge(array_map('strval', array_keys($this->databases)), array_keys($this->connections))));
    }

    public function disconnect(?string $name = null): void
    {
        if ($name === null) {
            foreach ($this->connections as $connection) {
                $connection->disconnect();
            }
        } elseif (isset($this->connections[$name])) {
            $this->connections[$name]->disconnect();
        }
    }

    private function make(string $name): Connection
    {
        return new Connection($name, $this->config($name), logging: $this->logQueries);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}

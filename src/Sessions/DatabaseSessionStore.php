<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

use BrewPHP\Db\Connection;
use BrewPHP\Db\IntegrityError;

/**
 * Sessions in the `sessions_session` table. Install the `brewphp.sessions`
 * app and run `migrate` to create it.
 */
final class DatabaseSessionStore implements SessionStore
{
    private const TABLE = 'sessions_session';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function read(string $id): ?array
    {
        $row = $this->connection->selectOne(
            sprintf('SELECT %s FROM %s WHERE %s = ? AND %s > ?', $this->q('session_data'), $this->q(self::TABLE), $this->q('session_key'), $this->q('expire_date')),
            [$id, self::date(time())],
        );
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['session_data'], true);

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $lifetime): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expires = self::date(time() + $lifetime);
        $update = fn (): int => $this->connection->statement(
            sprintf('UPDATE %s SET %s = ?, %s = ? WHERE %s = ?', $this->q(self::TABLE), $this->q('session_data'), $this->q('expire_date'), $this->q('session_key')),
            [$json, $expires, $id],
        );
        if ($update() > 0) {
            return;
        }
        try {
            $this->connection->insert(self::TABLE, ['session_key' => $id, 'session_data' => $json, 'expire_date' => $expires], null);
        } catch (IntegrityError) {
            // Another request created it meanwhile.
            $update();
        }
    }

    public function destroy(string $id): void
    {
        $this->connection->statement(sprintf('DELETE FROM %s WHERE %s = ?', $this->q(self::TABLE), $this->q('session_key')), [$id]);
    }

    public function exists(string $id): bool
    {
        return $this->connection->selectOne(sprintf('SELECT 1 FROM %s WHERE %s = ?', $this->q(self::TABLE), $this->q('session_key')), [$id]) !== null;
    }

    public function gc(): int
    {
        return $this->connection->statement(sprintf('DELETE FROM %s WHERE %s <= ?', $this->q(self::TABLE), $this->q('expire_date')), [self::date(time())]);
    }

    private function q(string $name): string
    {
        return $this->connection->grammar()->quoteIdentifier($name);
    }

    /**
     * A time in the format DateTimeField stores (the TIME_ZONE setting).
     */
    private static function date(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}

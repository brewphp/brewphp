<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

/**
 * Sessions in memory, for tests: they disappear when the process ends.
 * Data goes through JSON like the other stores, so tests catch values
 * that cannot be stored.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, array{expires: int, json: string}> */
    private static array $sessions = [];

    public function read(string $id): ?array
    {
        $entry = self::$sessions[$id] ?? null;
        if ($entry === null || $entry['expires'] <= time()) {
            return null;
        }
        $data = json_decode($entry['json'], true);

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $lifetime): void
    {
        self::$sessions[$id] = ['expires' => time() + $lifetime, 'json' => json_encode($data, JSON_THROW_ON_ERROR)];
    }

    public function destroy(string $id): void
    {
        unset(self::$sessions[$id]);
    }

    public function exists(string $id): bool
    {
        return isset(self::$sessions[$id]);
    }

    public function gc(): int
    {
        $expired = array_filter(self::$sessions, static fn (array $entry): bool => $entry['expires'] <= time());
        self::$sessions = array_diff_key(self::$sessions, $expired);

        return count($expired);
    }

    /**
     * Forget every session (between tests).
     */
    public static function reset(): void
    {
        self::$sessions = [];
    }
}

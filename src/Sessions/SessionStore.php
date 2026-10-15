<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

/**
 * Where session data lives. Data is a JSON-encodable array; stores never
 * use unserialize(), so session data can never create PHP objects.
 */
interface SessionStore
{
    /**
     * The data of a session, or null when it does not exist or expired.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $id): ?array;

    /**
     * Save the data of a session, valid for `$lifetime` seconds.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data, int $lifetime): void;

    public function destroy(string $id): void;

    public function exists(string $id): bool;

    /**
     * Delete expired sessions; returns how many were deleted.
     */
    public function gc(): int;
}

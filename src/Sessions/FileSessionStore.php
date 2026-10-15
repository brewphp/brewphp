<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

/**
 * Sessions in JSON files: `storage/sessions/<id>.json`, readable only by
 * the owner. The default store.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $id): ?array
    {
        $file = $this->file($id);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $payload = json_decode((string) @file_get_contents($file), true);
        if (!is_array($payload) || !is_int($payload['expires'] ?? null) || !is_array($payload['data'] ?? null)) {
            return null;
        }
        if ($payload['expires'] <= time()) {
            @unlink($file);

            return null;
        }

        /** @var array<string, mixed> */
        return $payload['data'];
    }

    public function write(string $id, array $data, int $lifetime): void
    {
        $file = $this->file($id) ?? throw new \InvalidArgumentException('Invalid session id.');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create the session folder "%s".', $this->directory));
        }
        $json = json_encode(['expires' => time() + $lifetime, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $isNew = !is_file($file);
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the session file.');
        }
        if ($isNew) {
            @chmod($file, 0600);
        }
    }

    public function destroy(string $id): void
    {
        $file = $this->file($id);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    public function exists(string $id): bool
    {
        $file = $this->file($id);

        return $file !== null && is_file($file);
    }

    public function gc(): int
    {
        $deleted = 0;
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $payload = json_decode((string) @file_get_contents($file), true);
            if (!is_array($payload) || !is_int($payload['expires'] ?? null) || $payload['expires'] <= time()) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The file of a session; null for an id that is not a valid session id
     * (so a crafted cookie can never point outside the folder).
     */
    private function file(string $id): ?string
    {
        return Session::isValidId($id) ? $this->directory . DIRECTORY_SEPARATOR . $id . '.json' : null;
    }
}

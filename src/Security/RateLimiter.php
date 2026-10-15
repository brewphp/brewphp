<?php

declare(strict_types=1);

namespace BrewPHP\Security;

use BrewPHP\Core\Application;

/**
 * Counts attempts per key in a time window, in small files, for example
 * to slow down password guessing:
 *
 *     $limiter = RateLimiter::make();
 *     $key = 'login|' . strtolower($username) . '|' . $request->ip();
 *     if ($limiter->tooManyAttempts($key, 5)) {
 *         // "Try again in {$limiter->availableIn($key)} seconds"
 *     }
 *     $limiter->hit($key, 900);   // after a failure; clear($key) after a success
 *
 * Keys are hashed, so they can hold user input.
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * A limiter that keeps its files in `storage/cache/ratelimit/`.
     */
    public static function make(): self
    {
        return new self(Application::getInstance()->storagePath('cache', 'ratelimit'));
    }

    /**
     * Count one attempt; the window starts with the first attempt and lasts
     * `$decaySeconds`. Returns the number of attempts in the window.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        return $this->update($key, static function (?array $entry) use ($decaySeconds): array {
            $now = time();
            if ($entry === null || $entry['reset'] <= $now) {
                return ['attempts' => 1, 'reset' => $now + $decaySeconds];
            }

            return ['attempts' => $entry['attempts'] + 1, 'reset' => $entry['reset']];
        })['attempts'];
    }

    public function attempts(string $key): int
    {
        return $this->read($key)['attempts'] ?? 0;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Seconds until the window of `$key` ends.
     */
    public function availableIn(string $key): int
    {
        $entry = $this->read($key);

        return $entry === null ? 0 : max(0, $entry['reset'] - time());
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return array{attempts: int, reset: int}|null
     */
    private function read(string $key): ?array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $entry = self::decode((string) @file_get_contents($file));

        return $entry !== null && $entry['reset'] > time() ? $entry : null;
    }

    /**
     * @param \Closure(?array{attempts: int, reset: int}): array{attempts: int, reset: int} $change
     * @return array{attempts: int, reset: int}
     */
    private function update(string $key, \Closure $change): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create the rate limit folder "%s".', $this->directory));
        }
        $handle = fopen($this->file($key), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the rate limit file.');
        }
        try {
            flock($handle, LOCK_EX);
            $entry = $change(self::decode((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($entry));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $entry;
    }

    /**
     * @return array{attempts: int, reset: int}|null
     */
    private static function decode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['attempts'] ?? null) || !is_int($data['reset'] ?? null)) {
            return null;
        }

        return ['attempts' => $data['attempts'], 'reset' => $data['reset']];
    }

    private function file(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}

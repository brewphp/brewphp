<?php

declare(strict_types=1);

namespace BrewPHP\Core;

/**
 * Loads `.env` files and reads environment variables.
 *
 * The format is the common one: `KEY=value` lines, `#` comments, optional
 * single or double quotes and an optional `export ` prefix. Variables that
 * already exist in the real environment are never overwritten, so a server
 * can always override what is in the file.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $loaded = [];

    /**
     * Load a `.env` file. A missing file is not an error.
     */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        foreach (self::parse((string) file_get_contents($file)) as $key => $value) {
            if (self::fromEnvironment($key) !== null) {
                continue;
            }
            self::$loaded[$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Parse `.env` contents into a key => value map.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $values[$key] = self::parseValue(trim($value));
        }

        return $values;
    }

    /**
     * Read a variable, converting "true", "false", "null" and "empty"
     * (in any case, optionally in parentheses) to their PHP values.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::fromEnvironment($key) ?? self::$loaded[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value, '()'))) {
            'true' => true,
            'false' => false,
            'null' => null,
            'empty' => '',
            default => $value,
        };
    }

    /**
     * Forget everything loaded from files (used by tests).
     */
    public static function reset(): void
    {
        foreach (array_keys(self::$loaded) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        self::$loaded = [];
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && ($end = strpos($value, $quote, 1)) !== false) {
            $inner = substr($value, 1, $end - 1);

            return $quote === '"' ? stripcslashes($inner) : $inner;
        }

        // Unquoted: strip an inline comment (" #...").
        $hash = strpos($value, ' #');

        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }

    private static function fromEnvironment(string $key): ?string
    {
        if (array_key_exists($key, self::$loaded)) {
            return null;
        }
        $value = getenv($key);

        return $value === false ? null : $value;
    }
}

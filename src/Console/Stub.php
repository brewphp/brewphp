<?php

declare(strict_types=1);

namespace BrewPHP\Console;

use BrewPHP\Core\Application;

/**
 * Code-generation templates for the `make:*` commands.
 *
 * Stubs live in the framework's `resources/stubs/` folder. A project can
 * override one by creating `stubs/<name>.stub` in its base folder.
 * Placeholders look like `{{ class }}`.
 */
final class Stub
{
    /**
     * @param array<string, string> $replacements
     */
    public static function render(Application $app, string $name, array $replacements): string
    {
        $file = $app->basePath('stubs', $name . '.stub');
        if (!is_file($file)) {
            $file = $app->resourcePath('stubs', $name . '.stub');
        }
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Stub "%s" not found.', $name));
        }

        $search = array_map(static fn (string $key): string => '{{ ' . $key . ' }}', array_keys($replacements));

        return str_replace($search, array_values($replacements), (string) file_get_contents($file));
    }

    /**
     * Render a stub into $target. Returns false when the file exists and
     * $force is not set.
     *
     * @param array<string, string> $replacements
     */
    public static function write(Application $app, string $name, string $target, array $replacements, bool $force = false): bool
    {
        if (is_file($target) && !$force) {
            return false;
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }
        file_put_contents($target, self::render($app, $name, $replacements));

        return true;
    }
}

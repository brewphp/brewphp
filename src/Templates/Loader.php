<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * Finds template files by name in a list of folders, first match wins:
 * the project's TEMPLATES DIRS, then each app's `templates/` folder, then
 * the framework's own templates.
 */
final class Loader
{
    /**
     * @param list<string> $directories
     */
    public function __construct(private array $directories = [])
    {
    }

    public function addDirectory(string $directory, bool $prepend = false): void
    {
        if ($prepend) {
            array_unshift($this->directories, $directory);
        } else {
            $this->directories[] = $directory;
        }
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /**
     * The absolute path of a template, or null.
     */
    public function find(string $name): ?string
    {
        return $this->search($name, $this->directories);
    }

    /**
     * Like find(), but only in the folders after the one holding $file.
     */
    public function findAfter(string $name, string $file): ?string
    {
        $normalizedFile = self::normalize($file);
        foreach ($this->directories as $i => $directory) {
            if (self::normalize(rtrim($directory, '/\\') . '/' . ltrim($name, '/\\')) === $normalizedFile) {
                return $this->search($name, array_slice($this->directories, $i + 1));
            }
        }

        return null;
    }

    /**
     * @param list<string> $directories
     */
    private function search(string $name, array $directories): ?string
    {
        if ($name === '' || str_contains($name, '..') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException(sprintf('Invalid template name "%s".', $name));
        }
        foreach ($directories as $directory) {
            $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . ltrim($name, '/\\');
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private static function normalize(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * Renders plain PHP templates (`.php` files).
 *
 * Templates are looked up by name in a list of folders, first match wins:
 * the project's TEMPLATES DIRS, then each app's `templates/` folder, then
 * the framework's own templates. That order lets a project override any
 * app or framework template by creating a file with the same name.
 *
 * Context values become local variables. Escape output with `e()`:
 *
 *     <h1><?= e($title) ?></h1>
 *
 * Projects usually go through the Templates facade, which uses this engine
 * for `.php` files and the template language for every other extension.
 */
final class PhpEngine
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

    public function exists(string $name): bool
    {
        return $this->locate($name) !== null;
    }

    /**
     * The absolute path of a template.
     *
     * @throws TemplateNotFound
     */
    public function find(string $name): string
    {
        return $this->locate($name) ?? throw new TemplateNotFound($name, $this->directories);
    }

    /**
     * Render the first template of $names that exists.
     *
     * @param string|list<string> $names
     * @param array<string, mixed> $context
     */
    public function render(string|array $names, array $context = []): string
    {
        $names = (array) $names;
        foreach ($names as $name) {
            if (($file = $this->locate($name)) !== null) {
                return self::evaluate($file, $context);
            }
        }

        throw new TemplateNotFound(implode(', ', $names), $this->directories);
    }

    /**
     * Render a template file that was already found.
     *
     * @param array<string, mixed> $context
     */
    public function renderFile(string $file, array $context = []): string
    {
        return self::evaluate($file, $context);
    }

    private function locate(string $name): ?string
    {
        if (str_contains($name, '..') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException(sprintf('Invalid template name "%s".', $name));
        }
        foreach ($this->directories as $directory) {
            $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . ltrim($name, '/\\');
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $__context
     */
    private static function evaluate(string $__file, array $__context): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function () use ($__file, $__context): void {
                extract($__context, EXTR_SKIP);
                require $__file;
            })();

            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}

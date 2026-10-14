<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\Templates;
use BrewPHP\Tests\TestCase;

/**
 * Helpers for template tests: a temporary template folder and cache.
 */
abstract class TemplateTestCase extends TestCase
{
    protected string $dir;

    protected string $cache;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->cache = $this->tempDir();
    }

    /**
     * @param list<callable> $contextProcessors
     */
    protected function templates(bool $cache = true, bool $strict = false, array $contextProcessors = []): Templates
    {
        return new Templates([$this->dir], $this->cache, cache: $cache, strict: $strict, contextProcessors: $contextProcessors);
    }

    /**
     * Render template source.
     *
     * @param array<string, mixed> $context
     */
    protected function render(string $source, array $context = [], bool $strict = false): string
    {
        return $this->templates(strict: $strict)->renderString($source, $context);
    }

    /**
     * Write template files: ['base.html' => '...'].
     *
     * @param array<string, string> $files
     */
    protected function write(array $files, ?string $dir = null): void
    {
        foreach ($files as $name => $source) {
            $path = ($dir ?? $this->dir) . DIRECTORY_SEPARATOR . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $source);
        }
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Http\Request;

/**
 * The one entry point for templates, `app()->templates()`.
 *
 * It finds a template by name in the template folders and renders it with
 * the engine that matches its extension: `.php` files are plain PHP
 * templates (PhpEngine), every other file (`.html`, `.txt`, `.xml`...) uses
 * the BrewPHP template language (Engine).
 *
 * When a Request is given, the context processors add their variables
 * first (the view's own context wins on a name clash).
 */
final class Templates
{
    private readonly Loader $loader;

    private readonly PhpEngine $php;

    private readonly Engine $engine;

    /** @var list<callable(Request): array<string, mixed>> */
    private array $contextProcessors;

    /**
     * @param list<string> $directories
     * @param list<callable(Request): array<string, mixed>> $contextProcessors
     */
    public function __construct(
        array $directories = [],
        ?string $cacheDirectory = null,
        bool $cache = true,
        bool $strict = false,
        array $contextProcessors = [],
        string $staticUrl = '/static/',
    ) {
        $this->loader = new Loader($directories);
        $this->php = new PhpEngine($directories);
        $this->engine = new Engine(
            $this->loader,
            $cacheDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'brewphp-templates',
            $cache,
            $strict,
            $staticUrl,
        );
        $this->contextProcessors = $contextProcessors;
    }

    /**
     * Render the first template of $names that exists.
     *
     * @param string|list<string> $names
     * @param array<string, mixed> $context
     * @throws TemplateNotFound
     */
    public function render(string|array $names, array $context = [], ?Request $request = null): string
    {
        if ($request !== null) {
            $context = array_replace($this->processContext($request), $context);
        }
        foreach ((array) $names as $name) {
            if (($file = $this->loader->find($name)) !== null) {
                return str_ends_with(strtolower($name), '.php')
                    ? $this->php->renderFile($file, $context)
                    : $this->engine->load($name, $file)->render($context);
            }
        }

        throw new TemplateNotFound(implode(', ', (array) $names), $this->loader->directories());
    }

    /**
     * Render template language source given as a string.
     *
     * @param array<string, mixed> $context
     */
    public function renderString(string $source, array $context = [], ?Request $request = null): string
    {
        if ($request !== null) {
            $context = array_replace($this->processContext($request), $context);
        }

        return $this->engine->fromString($source)->render($context);
    }

    /**
     * Register filters and tags (see Library).
     */
    public function library(Library $library): void
    {
        $this->engine->addLibrary($library);
    }

    /**
     * @param callable(Request): array<string, mixed> $processor
     */
    public function addContextProcessor(callable $processor): void
    {
        $this->contextProcessors[] = $processor;
    }

    /**
     * The variables every context processor adds for this request.
     *
     * @return array<string, mixed>
     */
    public function processContext(Request $request): array
    {
        $context = [];
        foreach ($this->contextProcessors as $processor) {
            $context = array_replace($context, $processor($request));
        }

        return $context;
    }

    public function exists(string $name): bool
    {
        return $this->loader->find($name) !== null;
    }

    /**
     * The absolute path of a template.
     *
     * @throws TemplateNotFound
     */
    public function find(string $name): string
    {
        return $this->loader->find($name) ?? throw new TemplateNotFound($name, $this->loader->directories());
    }

    public function addDirectory(string $directory, bool $prepend = false): void
    {
        $this->loader->addDirectory($directory, $prepend);
        $this->php->addDirectory($directory, $prepend);
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->loader->directories();
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    public function php(): PhpEngine
    {
        return $this->php;
    }
}

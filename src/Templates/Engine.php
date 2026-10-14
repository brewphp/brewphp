<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Http\HttpException;
use BrewPHP\Templates\Nodes\Node;
use BrewPHP\Templates\Nodes\NodeList;

/**
 * The BrewPHP template language (Django-like), compiled to PHP.
 *
 * A template is compiled once to a PHP file in the cache folder
 * (`storage/templates/`), which then simply runs on every render (and is
 * cached by OPcache). The compiled file remembers the modification time
 * and size of its source; when either changes, the template is compiled
 * again. With `cache: false` every template is compiled again once per
 * engine (that is, once per request).
 */
final class Engine
{
    /** Bumped when the compiled code changes, so old compiled files are ignored. */
    public const VERSION = '1';

    /** @var array<string, array{callable: callable, isSafe: bool, needsAutoescape: bool}> */
    private array $filters = [];

    /** @var array<string, callable(Parser, Token): Node> */
    private array $tags = [];

    /** @var array<string, array{callable: callable, takesContext: bool}> */
    private array $simpleTags = [];

    /** @var array<string, Template> loaded templates by source path (or string key) */
    private array $loaded = [];

    /** @var array<string, Template> loaded templates by compiled file path */
    private array $compiled = [];

    private ?string $fingerprint = null;

    public function __construct(
        private readonly Loader $loader,
        private readonly string $cacheDirectory,
        public readonly bool $cache = true,
        public readonly bool $strict = false,
        public readonly string $staticUrl = '/static/',
    ) {
        $this->addLibrary(Builtins::library());
    }

    public function addLibrary(Library $library): void
    {
        $this->filters = array_replace($this->filters, $library->filters());
        $this->tags = array_replace($this->tags, $library->tags());
        $this->simpleTags = array_replace($this->simpleTags, $library->simpleTags());
        $this->fingerprint = null;
    }

    public function loader(): Loader
    {
        return $this->loader;
    }

    /**
     * The first template of $names that exists, compiled and ready to render.
     *
     * @param string|list<string> $names
     * @throws TemplateNotFound
     */
    public function getTemplate(string|array $names): Template
    {
        foreach ((array) $names as $name) {
            if (($file = $this->loader->find($name)) !== null) {
                return $this->load($name, $file);
            }
        }

        throw new TemplateNotFound(implode(', ', (array) $names), $this->loader->directories());
    }

    /**
     * Load a template from a known file.
     */
    public function load(string $name, string $file): Template
    {
        if (isset($this->loaded[$file])) {
            return $this->loaded[$file];
        }
        if (str_ends_with(strtolower($file), '.php')) {
            // Compiling PHP source as template text would print it.
            throw new TemplateError(sprintf('"%s" is a PHP template: the template language cannot include or extend it.', $name));
        }
        clearstatcache(true, $file);
        $time = (int) filemtime($file);
        $size = (int) filesize($file);
        $compiled = $this->cachePath('file:' . $file);

        $template = null;
        if ($this->cache && is_file($compiled)) {
            $candidate = $this->include($compiled);
            if ($candidate->sourceTime === $time && $candidate->sourceSize === $size) {
                $template = $candidate;
            }
        }
        if ($template === null) {
            $source = (string) file_get_contents($file);
            try {
                $code = $this->compile($source, $name, $compiled, $time, $size);
            } catch (TemplateSyntaxError $e) {
                $e->templateFile = $file;
                throw $e;
            }
            $template = $this->write($compiled, $code);
        }

        $this->compiled[$compiled] = $template->bind($this, $name, $file);

        return $this->loaded[$file] = $template;
    }

    /**
     * Compile template source given as a string. `{% extends %}` and
     * `{% include %}` inside it load files as usual.
     */
    public function fromString(string $source, string $name = '<string>'): Template
    {
        $compiled = $this->cachePath('string:' . $name . "\0" . $source);
        if (isset($this->loaded[$compiled])) {
            return $this->loaded[$compiled];
        }
        $template = $this->cache && is_file($compiled)
            ? $this->include($compiled)
            : $this->write($compiled, $this->compile($source, $name, $compiled, 0, strlen($source)));

        $this->compiled[$compiled] = $template->bind($this, $name, null);

        return $this->loaded[$compiled] = $template;
    }

    /**
     * @param string|list<string> $names
     * @param array<string, mixed>|Context $context
     */
    public function render(string|array $names, array|Context $context = []): string
    {
        return $this->getTemplate($names)->render($context);
    }

    /**
     * The template that $child extends. When the parent has the same name
     * as the child, the search starts after the child's folder, so a
     * project template can extend the app template it overrides:
     * `templates/admin/base.html` may say `{% extends "admin/base.html" %}`.
     */
    public function getParent(mixed $parent, Template $child): Template
    {
        if ($parent instanceof Template) {
            return $parent;
        }
        $name = Runtime::toString($parent);
        if ($name === $child->name && $child->file !== null) {
            $file = $this->loader->findAfter($name, $child->file)
                ?? throw new TemplateNotFound($name . ' (extended by itself, after ' . $child->file . ')', $this->loader->directories());

            return $this->load($name, $file);
        }

        return $this->getTemplate($name);
    }

    /**
     * Compile template source to the PHP code of a compiled template file.
     */
    public function compile(string $source, string $name, string $key = '', int $time = 0, int $size = 0): string
    {
        try {
            $parser = new Parser($this, $name, $key !== '' ? basename($key, '.php') : sha1($name), Lexer::tokenize($source));
            $body = $parser->parse();
        } catch (TemplateSyntaxError $e) {
            throw $e->setLocation($name, $e->templateLine);
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Compiled BrewPHP template. Do not edit: it is rebuilt when its source changes.\n\n"
            . "return new \\BrewPHP\\Templates\\Template(\n"
            . '    ' . $time . ",\n"
            . '    ' . $size . ",\n";

        if ($parser->parent !== null) {
            $code .= "    static function (\\BrewPHP\\Templates\\Context \$c, \\BrewPHP\\Templates\\Runtime \$r, \\BrewPHP\\Templates\\Template \$t): \\BrewPHP\\Templates\\Template {\n"
                . '        // line ' . $parser->parentLine . "\n"
                . '        return $r->engine()->getParent(' . $parser->parent . ", \$t);\n"
                . "    },\n";
        } else {
            $code .= "    null,\n";
        }

        $code .= "    [\n";
        foreach ($parser->blocks as $blockName => $block) {
            $code .= '        ' . Compiler::string($blockName) . ' => ' . self::closure($block->body, $block->line, 2) . ",\n";
        }
        $code .= "    ],\n";
        // A child template only defines blocks: anything outside them is ignored.
        $code .= '    ' . self::closure($parser->parent === null ? $body : new NodeList(), 1, 1) . ",\n);\n";

        return $code;
    }

    /**
     * Wrap an error raised while rendering in a TemplateError that knows
     * the template name and line (found from the `// line N` comments of
     * the compiled file). HTTP exceptions such as Http404 pass unchanged.
     */
    public function locateError(\Throwable $e): \Throwable
    {
        if ($e instanceof HttpException || ($e instanceof TemplateError && $e->templateName !== null)) {
            return $e;
        }
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || !isset($this->compiled[$file])) {
                continue;
            }
            $template = $this->compiled[$file];
            $line = self::sourceLine($file, $frame['line'] ?? 0);
            $error = $e instanceof TemplateError ? $e : new TemplateError($e->getMessage(), 0, $e);

            return $error->setLocation($template->name, $line, $template->file);
        }

        return $e;
    }

    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    /**
     * @return array{callable: callable, isSafe: bool, needsAutoescape: bool}
     */
    public function filterDefinition(string $name): array
    {
        return $this->filters[$name] ?? throw new TemplateError(sprintf('Unknown filter "%s".', $name));
    }

    /**
     * @return (callable(Parser, Token): Node)|null
     */
    public function tagCompiler(string $name): ?callable
    {
        return $this->tags[$name] ?? null;
    }

    /**
     * @return array{callable: callable, takesContext: bool}
     */
    public function simpleTagDefinition(string $name): array
    {
        return $this->simpleTags[$name] ?? throw new TemplateError(sprintf('Unknown tag "%s".', $name));
    }

    // Internals -----------------------------------------------------------------

    private static function closure(NodeList $nodes, int $line, int $indent): string
    {
        $compiler = new Compiler($indent + 1);
        $compiler->line($line);
        $compiler->subcompile($nodes);
        $pad = str_repeat('    ', $indent);

        return "static function (\\BrewPHP\\Templates\\Context \$c, \\BrewPHP\\Templates\\Runtime \$r): void {\n"
            . $compiler->code()
            . $pad . '}';
    }

    /**
     * The compiled file for a source. Its name includes the engine version
     * and the registered tags, because both change the compiled code.
     */
    private function cachePath(string $source): string
    {
        $this->fingerprint ??= implode(',', array_keys($this->tags));

        return rtrim($this->cacheDirectory, '/\\') . DIRECTORY_SEPARATOR
            . sha1(self::VERSION . "\0" . $this->fingerprint . "\0" . $source) . '.php';
    }

    private function include(string $compiled): Template
    {
        $template = (static fn (): mixed => require $compiled)();
        if (!$template instanceof Template) {
            throw new TemplateError(sprintf('The compiled template "%s" is invalid. Delete it to compile it again.', $compiled));
        }

        return $template;
    }

    /**
     * Write a compiled file atomically (temporary file, then rename) and load it.
     */
    private function write(string $compiled, string $code): Template
    {
        $directory = dirname($compiled);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new TemplateError(sprintf('Cannot create the template cache folder "%s".', $directory));
        }
        $temporary = $compiled . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $code) === false) {
            throw new TemplateError(sprintf('Cannot write compiled templates to "%s". Make the folder writable.', $directory));
        }
        if (!@rename($temporary, $compiled)) {
            // Another process may hold the file (Windows): use our copy this time.
            try {
                return $this->include($temporary);
            } finally {
                @unlink($temporary);
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($compiled, true);
        }

        return $this->include($compiled);
    }

    /**
     * The template line of a compiled file line: the closest `// line N`
     * comment above it.
     */
    private static function sourceLine(string $compiled, int $line): ?int
    {
        $lines = @file($compiled, FILE_IGNORE_NEW_LINES) ?: [];
        for ($i = min($line, count($lines)) - 1; $i >= 0; $i--) {
            if (preg_match('#^\s*// line (\d+)$#', $lines[$i], $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }
}

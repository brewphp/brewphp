<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * A compiled template, ready to render. Each compiled file in
 * `storage/templates/` returns one; get it with `Engine::getTemplate()`.
 *
 * Rendering follows `{% extends %}` up to the root template, collecting
 * blocks on the way (the most derived block wins), then runs the root
 * template's body.
 */
final class Template
{
    public string $name = '<unknown>';

    /** Absolute path of the source file, null for a string template. */
    public ?string $file = null;

    private ?Engine $engine = null;

    /**
     * @param (\Closure(Context, Runtime, Template): Template)|null $parent loads the template to extend
     * @param array<string, \Closure(Context, Runtime): void> $blocks
     * @param \Closure(Context, Runtime): void $body
     */
    public function __construct(
        public readonly int $sourceTime,
        public readonly int $sourceSize,
        public readonly ?\Closure $parent,
        public readonly array $blocks,
        private readonly \Closure $body,
    ) {
    }

    /**
     * Attach the template to its engine (done when it is loaded).
     */
    public function bind(Engine $engine, string $name, ?string $file): self
    {
        $this->engine = $engine;
        $this->name = $name;
        $this->file = $file;

        return $this;
    }

    /**
     * @param array<string, mixed>|Context $context
     */
    public function render(array|Context $context = []): string
    {
        $engine = $this->engine ?? throw new \LogicException('The template is not bound to an engine.');
        $context = $context instanceof Context ? $context : new Context($context);
        $runtime = new Runtime($engine);
        $level = ob_get_level();

        try {
            $template = $this;
            $seen = [];
            while (true) {
                $runtime->addBlocks($template->blocks);
                if ($template->parent === null) {
                    break;
                }
                $seen[$template->file ?? $template->name] = true;
                $parent = ($template->parent)($context, $runtime, $template);
                if (isset($seen[$parent->file ?? $parent->name])) {
                    throw new TemplateError(sprintf('Template "%s" extends itself through "%s".', $this->name, $parent->name));
                }
                $template = $parent;
            }

            ob_start();
            ($template->body)($context, $runtime);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            throw $engine->locateError($e);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}

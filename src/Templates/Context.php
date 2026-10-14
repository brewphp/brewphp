<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * The variables of a template while it renders.
 *
 * Tags such as `{% for %}`, `{% with %}` and `{% include %}` push a new
 * layer and pop it at the end, so the variables they set disappear
 * afterwards. A value that is a Closure is called (without arguments) the
 * first time a template reads it, which keeps expensive values lazy.
 */
final class Context
{
    /** @var list<array<string, mixed>> */
    private array $stack = [];

    /**
     * @param array<string, mixed> $vars
     */
    public function __construct(public array $vars = [])
    {
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function push(array $vars = []): void
    {
        $this->stack[] = $this->vars;
        $this->vars = array_replace($this->vars, $vars);
    }

    public function pop(): void
    {
        $this->vars = array_pop($this->stack) ?? $this->vars;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->vars);
    }

    /**
     * A variable's value (a lazy Closure value is called).
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->vars)) {
            return $default;
        }
        $value = $this->vars[$name];

        return $value instanceof \Closure ? $value() : $value;
    }

    public function set(string $name, mixed $value): void
    {
        $this->vars[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->vars;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * `{{ expression }}`: prints a value, HTML-escaped unless auto-escaping
 * is off or the value is a SafeString.
 */
final class VariableNode extends Node
{
    /**
     * @param string $expression compiled PHP expression
     */
    public function __construct(public readonly string $expression, public readonly bool $autoescape = true)
    {
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->write(sprintf('echo $r->%s(%s);', $this->autoescape ? 'escape' : 'toString', $this->expression));
    }
}

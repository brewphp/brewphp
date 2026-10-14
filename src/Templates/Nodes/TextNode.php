<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * Plain template text, printed as it is.
 */
final class TextNode extends Node
{
    public function __construct(public readonly string $text)
    {
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->write('echo ' . Compiler::string($this->text) . ';');
    }
}

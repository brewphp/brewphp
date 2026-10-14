<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * `{% block name %}`: a named section that a child template can replace.
 * Its content is compiled separately; here it only renders the most
 * derived version of the block.
 */
final class BlockNode extends Node
{
    public function __construct(public readonly string $name, public readonly NodeList $body)
    {
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->write(sprintf('$r->renderBlock(%s, $c);', Compiler::string($this->name)));
    }
}

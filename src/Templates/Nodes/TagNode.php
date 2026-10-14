<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * A node whose PHP code is written by a closure. Most tags use it:
 *
 *     return new TagNode(function (Compiler $compiler) use ($value): void {
 *         $compiler->write('echo $r->escape(' . $value . ');');
 *     });
 */
final class TagNode extends Node
{
    /**
     * @param \Closure(Compiler): void $compile
     */
    public function __construct(private readonly \Closure $compile)
    {
    }

    public function compile(Compiler $compiler): void
    {
        ($this->compile)($compiler);
    }
}

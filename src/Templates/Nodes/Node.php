<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * A piece of a parsed template. It knows how to write itself as PHP code.
 */
abstract class Node
{
    /** Line of the template where the node starts (set by the parser). */
    public int $line = 0;

    abstract public function compile(Compiler $compiler): void;
}

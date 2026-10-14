<?php

declare(strict_types=1);

namespace BrewPHP\Templates\Nodes;

use BrewPHP\Templates\Compiler;

/**
 * The nodes between a tag and its end tag (or a whole template).
 */
final class NodeList extends Node
{
    /**
     * @param list<Node> $nodes
     */
    public function __construct(public array $nodes = [])
    {
    }

    public function compile(Compiler $compiler): void
    {
        foreach ($this->nodes as $node) {
            $compiler->line($node->line);
            $node->compile($compiler);
        }
    }
}

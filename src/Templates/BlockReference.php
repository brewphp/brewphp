<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * The `block` variable inside `{% block %}`: `{{ block.super }}` renders
 * the same block as the parent template defines it.
 */
final class BlockReference
{
    public function __construct(
        private readonly Runtime $runtime,
        public readonly string $name,
        private readonly int $depth,
        private readonly Context $context,
    ) {
    }

    public function super(): SafeString
    {
        return new SafeString($this->runtime->captureBlock($this->name, $this->context, $this->depth + 1));
    }
}

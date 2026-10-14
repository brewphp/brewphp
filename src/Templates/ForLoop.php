<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * The `forloop` variable inside `{% for %}`.
 */
final class ForLoop
{
    /** The current iteration, starting at 1. */
    public int $counter = 0;

    /** The current iteration, starting at 0. */
    public int $counter0 = -1;

    /** Iterations left, ending at 1. */
    public int $revcounter;

    /** Iterations left, ending at 0. */
    public int $revcounter0;

    public bool $first = false;

    public bool $last = false;

    public function __construct(public readonly int $length, public readonly ?ForLoop $parentloop = null)
    {
        $this->revcounter = $length + 1;
        $this->revcounter0 = $length;
    }

    /**
     * Move to the next iteration (called by the compiled loop).
     */
    public function advance(): void
    {
        $this->counter++;
        $this->counter0++;
        $this->revcounter--;
        $this->revcounter0--;
        $this->first = $this->counter === 1;
        $this->last = $this->counter === $this->length;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A reference to a column, computed by the database, like Django's F():
 *
 *     Post::objects()->filter(pk: 5)->update(['views' => (new F('views'))->add(1)]);
 *     Post::objects()->filter(likes__gt: new F('views'));
 *
 * The name can follow relations (`new F('author__score')`) in filters.
 */
final class F
{
    /** @var list<array{0: string, 1: F|int|float}> arithmetic applied in order */
    public array $operations = [];

    public function __construct(public readonly string $name)
    {
    }

    public function add(F|int|float $value): self
    {
        return $this->with('+', $value);
    }

    public function subtract(F|int|float $value): self
    {
        return $this->with('-', $value);
    }

    public function multiply(F|int|float $value): self
    {
        return $this->with('*', $value);
    }

    public function divide(F|int|float $value): self
    {
        return $this->with('/', $value);
    }

    private function with(string $operator, F|int|float $value): self
    {
        $copy = clone $this;
        $copy->operations[] = [$operator, $value];

        return $copy;
    }
}

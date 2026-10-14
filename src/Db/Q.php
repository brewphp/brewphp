<?php

declare(strict_types=1);

namespace BrewPHP\Db;

/**
 * A group of lookups that can be combined with AND, OR and NOT, like
 * Django's Q objects:
 *
 *     Post::objects()->where(Q::or(new Q(title__icontains: 'php'), new Q(views__gte: 100)));
 *     Post::objects()->where(Q::not(new Q(status: 'draft')));
 *
 * The lookups given to one Q are combined with AND.
 */
final class Q
{
    public const AND = 'AND';
    public const OR = 'OR';

    /** @var list<Q|array{0: string, 1: mixed}> lookups as [key, value] pairs, or nested Q objects */
    public array $children = [];

    public string $connector = self::AND;

    public bool $negated = false;

    /**
     * @param mixed ...$lookups named lookups (`title__icontains: 'php'`), arrays of them, or Q objects
     */
    public function __construct(mixed ...$lookups)
    {
        foreach ($lookups as $key => $value) {
            if (is_string($key)) {
                $this->children[] = [$key, $value];
            } elseif ($value instanceof self) {
                $this->children[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $name => $item) {
                    if (!is_string($name)) {
                        throw new FieldError('Lookups in an array must be keyed by name, e.g. [\'title__icontains\' => \'php\'].');
                    }
                    $this->children[] = [$name, $item];
                }
            } else {
                throw new FieldError(sprintf('Expected a named lookup, an array of lookups or a Q object, got %s.', get_debug_type($value)));
            }
        }
    }

    /**
     * True when any of the conditions is true.
     */
    public static function or(Q ...$conditions): self
    {
        return self::combine(self::OR, $conditions);
    }

    /**
     * True when all of the conditions are true.
     */
    public static function and(Q ...$conditions): self
    {
        return self::combine(self::AND, $conditions);
    }

    /**
     * True when the condition is false.
     */
    public static function not(Q $condition): self
    {
        return $condition->negate();
    }

    /**
     * A copy of this condition, negated.
     */
    public function negate(): self
    {
        $copy = clone $this;
        $copy->negated = !$this->negated;

        return $copy;
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    /**
     * @param array<Q> $conditions
     */
    private static function combine(string $connector, array $conditions): self
    {
        $q = new self();
        $q->connector = $connector;
        $q->children = array_values($conditions);

        return $q;
    }
}

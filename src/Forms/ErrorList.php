<?php

declare(strict_types=1);

namespace BrewPHP\Forms;

use BrewPHP\Templates\HtmlSafe;

/**
 * The error messages of a field (or of the whole form). Printed in a
 * template it becomes `<ul class="errorlist">`, with every message escaped.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ErrorList implements HtmlSafe, \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param list<string> $messages
     */
    public function __construct(private readonly array $messages = [], private readonly ?string $id = null, private readonly string $class = 'errorlist')
    {
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function asUl(): string
    {
        if ($this->messages === []) {
            return '';
        }
        $items = '';
        foreach ($this->messages as $message) {
            $items .= '<li>' . Html::escape($message) . '</li>';
        }

        return Html::tag('ul', ['class' => $this->class, 'id' => $this->id], $items);
    }

    public function asText(): string
    {
        return implode("\n", array_map(static fn (string $message): string => '* ' . $message, $this->messages));
    }

    public function __toString(): string
    {
        return $this->asUl();
    }

    /**
     * @return \ArrayIterator<int, string>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->messages);
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->messages;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * A string that is already safe HTML, so auto-escaping leaves it alone.
 *
 * Built-in filters such as `|safe`, `|escape` and `|linebreaks` return one;
 * so can your own filters and tags. Only wrap HTML you built yourself or
 * escaped: wrapping user input is an XSS hole.
 *
 *     return new SafeString('<b>' . e($name) . '</b>');
 */
final class SafeString implements \Stringable, \JsonSerializable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}

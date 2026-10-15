<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

/**
 * One flash message. Printing it prints its text (escaped by templates).
 */
final class Message implements \Stringable, \JsonSerializable
{
    public function __construct(public readonly string $level, public readonly string $message)
    {
    }

    /**
     * The level as a CSS class, e.g. "success".
     */
    public function tags(): string
    {
        return $this->level;
    }

    public function __toString(): string
    {
        return $this->message;
    }

    /**
     * @return array{level: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return ['level' => $this->level, 'message' => $this->message];
    }
}

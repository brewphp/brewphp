<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * One piece of template source: text, `{{ variable }}`, `{% tag %}` or
 * `{# comment #}`. `contents` is what is between the delimiters, trimmed.
 */
final class Token
{
    public const TEXT = 'text';
    public const VARIABLE = 'variable';
    public const BLOCK = 'block';
    public const COMMENT = 'comment';

    public function __construct(
        public readonly string $type,
        public readonly string $contents,
        public readonly int $line,
    ) {
    }

    /**
     * The tag name of a block token: "for" in `{% for x in items %}`.
     */
    public function tagName(): string
    {
        return explode(' ', (string) preg_replace('/\s+/', ' ', $this->contents), 2)[0];
    }

    /**
     * What follows the tag name: "x in items" in `{% for x in items %}`.
     */
    public function tagArguments(): string
    {
        return trim(substr(ltrim($this->contents), strlen($this->tagName())));
    }
}

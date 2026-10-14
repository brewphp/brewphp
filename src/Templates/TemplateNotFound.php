<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * Raised when no template folder contains the requested template.
 */
final class TemplateNotFound extends \RuntimeException
{
    /**
     * @param list<string> $searched
     */
    public function __construct(public readonly string $template, public readonly array $searched = [])
    {
        parent::__construct(sprintf(
            'Template "%s" not found. Searched in: %s',
            $template,
            $searched === [] ? '(no template folders)' : implode(', ', $searched),
        ));
    }
}

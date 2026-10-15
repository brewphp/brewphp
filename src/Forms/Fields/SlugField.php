<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

/**
 * Letters, numbers, underscores and hyphens.
 */
class SlugField extends CharField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid “slug” consisting of letters, numbers, underscores or hyphens.',
    ] + parent::MESSAGES;

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (is_string($value) && $value !== '' && preg_match('/^[-a-zA-Z0-9_]+$/', $value) !== 1) {
            throw $this->error('invalid');
        }
    }
}

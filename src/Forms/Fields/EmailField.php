<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\EmailInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * An email address (at most 320 characters by default).
 */
class EmailField extends CharField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid email address.',
    ] + parent::MESSAGES;

    public function __construct(?int $maxLength = 320, mixed ...$options)
    {
        parent::__construct($maxLength, ...$options);
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->error('invalid');
        }
    }

    protected function defaultWidget(): Widget
    {
        return new EmailInput();
    }
}

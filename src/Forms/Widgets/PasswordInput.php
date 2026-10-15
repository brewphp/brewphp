<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <input type="password">. The value is never written back into the page
 * unless `renderValue` is true, so a password does not travel back to
 * the browser after a failed submit.
 */
class PasswordInput extends Input
{
    protected string $inputType = 'password';

    /**
     * @param array<string, mixed> $attrs
     */
    public function __construct(array $attrs = [], public bool $renderValue = false)
    {
        parent::__construct($attrs);
    }

    public function render(string $name, mixed $value, array $attrs = []): string
    {
        return parent::render($name, $this->renderValue ? $value : null, $attrs);
    }
}

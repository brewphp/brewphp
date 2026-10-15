<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <input type="hidden">.
 */
class HiddenInput extends Input
{
    protected string $inputType = 'hidden';

    public bool $isHidden = true;
}

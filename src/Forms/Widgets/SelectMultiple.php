<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * <select multiple>. The field name gets "[]" so PHP receives a list.
 */
class SelectMultiple extends Select
{
    protected bool $multiple = true;
}

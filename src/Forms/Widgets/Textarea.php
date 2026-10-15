<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * <textarea>.
 */
class Textarea extends Widget
{
    /**
     * @param array<string, mixed> $attrs
     */
    public function __construct(array $attrs = [])
    {
        parent::__construct(['cols' => 40, 'rows' => 10, ...$attrs]);
    }

    public function render(string $name, mixed $value, array $attrs = []): string
    {
        // A newline after the tag keeps a leading newline of the value.
        return Html::tag('textarea', $this->buildAttrs(['name' => $name, ...$attrs]), "\n" . Html::escape($this->formatValue($value) ?? ''));
    }
}

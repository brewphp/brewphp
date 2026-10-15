<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * <select> with one <option> per choice.
 */
class Select extends ChoiceWidget
{
    public function render(string $name, mixed $value, array $attrs = []): string
    {
        $selected = $this->selectedValues($value);
        $options = '';
        foreach ($this->choices as $choice => $label) {
            $options .= "\n" . Html::tag('option', ['value' => (string) $choice, 'selected' => in_array((string) $choice, $selected, true)], Html::escape($label));
        }

        return Html::tag('select', $this->buildAttrs(['name' => $this->fieldName($name), 'multiple' => $this->multiple, ...$attrs]), $options . "\n");
    }

    public function useRequiredAttribute(mixed $initial): bool
    {
        // `required` on a single <select> only works when the first option is empty.
        return parent::useRequiredAttribute($initial) && ($this->multiple || array_key_first($this->choices) === '');
    }
}

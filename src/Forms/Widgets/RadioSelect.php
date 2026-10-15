<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * A list of radio buttons, one per choice.
 */
class RadioSelect extends ChoiceWidget
{
    protected string $inputType = 'radio';

    public function render(string $name, mixed $value, array $attrs = []): string
    {
        $selected = $this->selectedValues($value);
        $id = is_string($attrs['id'] ?? null) ? $attrs['id'] : null;
        unset($attrs['id']);
        $items = '';
        $i = 0;
        foreach ($this->choices as $choice => $label) {
            $itemId = $id !== null ? $id . '_' . $i : null;
            $input = Html::tag('input', $this->buildAttrs([
                ...$attrs,
                'type' => $this->inputType,
                'name' => $this->fieldName($name),
                'value' => (string) $choice,
                'checked' => in_array((string) $choice, $selected, true),
                'id' => $itemId,
                // `required` on every checkbox would demand all of them.
                'required' => $this->multiple ? null : ($attrs['required'] ?? null),
            ]));
            $items .= "\n" . Html::tag('div', [], Html::tag('label', ['for' => $itemId], $input . ' ' . Html::escape($label)));
            $i++;
        }

        return Html::tag('div', ['id' => $id], $items . "\n");
    }

    public function idForLabel(string $id): string
    {
        return $id . '_0';
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * Base class of widgets that show choices. ChoiceField and friends set
 * `$choices` (value => label) from the field.
 */
abstract class ChoiceWidget extends Widget
{
    /** Whether several values can be chosen (the name then ends with "[]"). */
    protected bool $multiple = false;

    /**
     * @param array<string, mixed> $attrs
     * @param array<int|string, string> $choices
     */
    public function __construct(array $attrs = [], public array $choices = [])
    {
        parent::__construct($attrs);
    }

    public function valueFromData(array $data, array $files, string $name): mixed
    {
        $value = $data[$name] ?? null;
        if ($this->multiple) {
            return is_array($value) ? array_values($value) : ($value === null ? [] : [$value]);
        }

        return $value;
    }

    public function valueOmittedFromData(array $data, array $files, string $name): bool
    {
        // Browsers send nothing when no box is checked.
        return !$this->multiple && parent::valueOmittedFromData($data, $files, $name);
    }

    /**
     * The chosen values, as strings.
     *
     * @return list<string>
     */
    protected function selectedValues(mixed $value): array
    {
        $selected = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $formatted = $this->formatValue($item);
            if ($formatted !== null) {
                $selected[] = $formatted;
            }
        }

        return $selected;
    }

    protected function fieldName(string $name): string
    {
        return $this->multiple ? $name . '[]' : $name;
    }
}

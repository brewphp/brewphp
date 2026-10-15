<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\SelectMultiple;
use BrewPHP\Forms\Widgets\Widget;

/**
 * Several values from a fixed list, cleaned to a list.
 */
class MultipleChoiceField extends ChoiceField
{
    protected const MESSAGES = [
        'invalid_list' => 'Enter a list of values.',
    ] + parent::MESSAGES;

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw $this->error('invalid_list');
        }
        $values = [];
        foreach ($value as $item) {
            if ($item instanceof \BackedEnum) {
                $item = $item->value;
            }
            if (!is_scalar($item)) {
                throw $this->error('invalid_list');
            }
            $values[] = (string) $item;
        }

        return array_values(array_unique($values));
    }

    public function validate(mixed $value): void
    {
        if ($this->required && $value === []) {
            throw $this->error('required');
        }
        foreach ((array) $value as $item) {
            if (!$this->isValidChoice((string) $item)) {
                throw $this->error('invalid_choice', ['value' => (string) $item]);
            }
        }
    }

    public function clean(mixed $value): mixed
    {
        $values = $this->toPhp($value);
        $this->validate($values);
        foreach ($this->validators as $validator) {
            $validator($values);
        }
        /** @var list<string> $values */
        if ($this->enum !== null) {
            $enum = $this->enum;
            $isInt = is_int(array_key_first($this->choices));

            return array_values(array_filter(array_map(static fn (string $v): ?\BackedEnum => $enum::tryFrom($isInt ? (int) $v : $v), $values)));
        }

        return $this->coerce !== null ? array_map($this->coerce, $values) : $values;
    }

    public function prepareValue(mixed $value): mixed
    {
        return is_array($value) ? array_map(static fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : $v, $value) : $value;
    }

    protected function defaultWidget(): Widget
    {
        return new SelectMultiple();
    }
}

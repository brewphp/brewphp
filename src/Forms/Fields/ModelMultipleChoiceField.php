<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Widgets\SelectMultiple;
use BrewPHP\Forms\Widgets\Widget;

/**
 * Several objects from a QuerySet, cleaned to a list of model instances.
 * Every submitted key must belong to the QuerySet.
 */
class ModelMultipleChoiceField extends ModelChoiceField
{
    protected const MESSAGES = [
        'invalid_list' => 'Enter a list of values.',
        'invalid_choice' => 'Select a valid choice. {value} is not one of the available choices.',
    ];

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw $this->error('invalid_list');
        }
        $objects = [];
        foreach ($value as $item) {
            if ($item instanceof Model) {
                $objects[] = $item;
                continue;
            }
            if (!is_scalar($item)) {
                throw $this->error('invalid_list');
            }
            $key = trim((string) $item);
            if ($key === '') {
                continue;
            }
            try {
                $object = $this->lookup($key);
            } catch (ValidationError) {
                throw $this->error('invalid_choice', ['value' => $key]);
            }
            if ($object !== null) {
                $objects[$this->identity($object)] = $object;
            }
        }

        return array_values($objects);
    }

    public function validate(mixed $value): void
    {
        if ($this->required && $value === []) {
            throw $this->error('required');
        }
    }

    public function prepareValue(mixed $value): mixed
    {
        return is_array($value) ? array_map(fn (mixed $v): mixed => $v instanceof Model ? $v->getAttribute($this->keyAttribute($v)) : $v, $value) : $value;
    }

    protected function defaultWidget(): Widget
    {
        return new SelectMultiple();
    }

    private function identity(Model $object): string
    {
        $key = $object->getAttribute($this->keyAttribute($object));

        return is_scalar($key) ? (string) $key : spl_object_hash($object);
    }
}

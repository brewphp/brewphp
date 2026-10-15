<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\ChoiceWidget;
use BrewPHP\Forms\Widgets\Select;
use BrewPHP\Forms\Widgets\SelectMultiple;
use BrewPHP\Forms\Widgets\Widget;

/**
 * One value from a fixed list. Anything else is rejected, whatever the
 * browser sends.
 *
 *     new ChoiceField(choices: ['tea' => 'Tea', 'coffee' => 'Coffee'])
 *     new ChoiceField(choices: Status::class)   // a backed enum: cleaned to the case
 *
 * `coerce` turns the chosen string into another value (TypedChoiceField).
 */
class ChoiceField extends Field
{
    protected const MESSAGES = [
        'invalid_choice' => 'Select a valid choice. {value} is not one of the available choices.',
    ];

    /** @var array<int|string, string> */
    public array $choices = [];

    /** @var class-string<\BackedEnum>|null */
    public ?string $enum = null;

    /**
     * @param array<int|string, string>|class-string<\BackedEnum> $choices value => label, or a backed enum class
     * @param (\Closure(string): mixed)|null $coerce
     */
    public function __construct(array|string $choices = [], public ?\Closure $coerce = null, mixed ...$options)
    {
        parent::__construct(...$options);
        $this->setChoices($choices);
    }

    /**
     * @param array<int|string, string>|class-string<\BackedEnum> $choices
     */
    public function setChoices(array|string $choices): void
    {
        if (is_string($choices)) {
            if (!is_subclass_of($choices, \BackedEnum::class)) {
                throw new \InvalidArgumentException(sprintf('Choices must be an array or a BackedEnum class, "%s" given.', $choices));
            }
            $this->enum = $choices;
            $list = [];
            foreach ($choices::cases() as $case) {
                $list[$case->value] = method_exists($case, 'label') ? (string) $case->label() : ucfirst(strtolower(str_replace('_', ' ', $case->name)));
            }
            $choices = $list;
        }
        $this->choices = $choices;
        $this->refreshChoices();
    }

    /**
     * Give the widget the current choices. Forms call it before rendering.
     */
    public function refreshChoices(): void
    {
        if ($this->widget instanceof ChoiceWidget) {
            $this->widget->choices = $this->widgetChoices();
        }
    }

    public function toPhp(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $this->stringValue($value, strip: false);
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if ($value !== null && !$this->isValidChoice((string) $value)) {
            throw $this->error('invalid_choice', ['value' => (string) $value]);
        }
    }

    public function clean(mixed $value): mixed
    {
        $value = parent::clean($value);
        if ($value === null) {
            return null;
        }
        if ($this->enum !== null) {
            $enum = $this->enum;

            return $enum::tryFrom(is_int(array_key_first($this->choices)) ? (int) $value : (string) $value);
        }

        return $this->coerce !== null ? ($this->coerce)((string) $value) : $value;
    }

    public function prepareValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    protected function isValidChoice(string $value): bool
    {
        foreach (array_keys($this->choices) as $choice) {
            if ((string) $choice === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The choices shown in the widget: an empty option first for a field
     * that is not required (or has no initial value) in a <select>.
     *
     * @return array<int|string, string>
     */
    protected function widgetChoices(): array
    {
        if ($this->widget instanceof Select && !$this->widget instanceof SelectMultiple && !array_key_exists('', $this->choices)) {
            return ['' => '---------'] + $this->choices;
        }

        return $this->choices;
    }

    protected function defaultWidget(): Widget
    {
        return new Select();
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Widgets\TextInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A form field: turns submitted text into a clean PHP value, or raises
 * ValidationError with a message for the user.
 *
 *     'email' => new Forms\EmailField(label: 'Your email', helpText: 'We never share it.'),
 *
 * Submitted values are untrusted: a field only accepts the shape it expects
 * (a string, or a list for multiple choices), never anything else.
 */
abstract class Field
{
    /** Default messages by code; `$errorMessages` replaces them per field. */
    protected const MESSAGES = [
        'required' => 'This field is required.',
        'invalid' => 'Enter a valid value.',
    ];

    public Widget $widget;

    /**
     * @param bool $required the field must not be empty
     * @param Widget|null $widget how it is shown (each field has a sensible default)
     * @param string|null $label the label (default: the field name, capitalised)
     * @param mixed $initial the value of an unbound form (a closure is called)
     * @param string $helpText shown next to the widget
     * @param array<string, string> $errorMessages messages by code, e.g. ['required' => 'Please tell us.']
     * @param list<callable(mixed): void> $validators callables that throw ValidationError
     * @param bool $disabled shown but not editable: the submitted value is ignored
     */
    public function __construct(
        public bool $required = true,
        ?Widget $widget = null,
        public ?string $label = null,
        public mixed $initial = null,
        public string $helpText = '',
        public array $errorMessages = [],
        public array $validators = [],
        public bool $disabled = false,
    ) {
        $this->widget = $widget ?? $this->defaultWidget();
    }

    /**
     * Convert, validate and run the validators; returns the clean value.
     *
     * @throws ValidationError
     */
    public function clean(mixed $value): mixed
    {
        $value = $this->toPhp($value);
        $this->validate($value);
        $messages = [];
        foreach ($this->validators as $validator) {
            try {
                $validator($value);
            } catch (ValidationError $e) {
                array_push($messages, ...$e->messages());
            }
        }
        if ($messages !== []) {
            throw new ValidationError($messages);
        }

        return $value;
    }

    /**
     * The submitted value as a PHP value (null when empty).
     */
    public function toPhp(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Checks on the PHP value; by default, that a required field is not empty.
     */
    public function validate(mixed $value): void
    {
        if ($this->required && self::isEmpty($value)) {
            throw $this->error('required');
        }
    }

    /**
     * HTML attributes the field adds to its widget, e.g. maxlength.
     *
     * @return array<string, mixed>
     */
    public function widgetAttrs(Widget $widget): array
    {
        return [];
    }

    /**
     * The value to show in the widget for an unbound form.
     */
    public function prepareValue(mixed $value): mixed
    {
        return $value;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected function defaultWidget(): Widget
    {
        return new TextInput();
    }

    /**
     * @param array<string, string|int|float> $params values for "%(name)s" style placeholders
     */
    protected function error(string $code, array $params = []): ValidationError
    {
        $message = $this->errorMessages[$code] ?? static::MESSAGES[$code] ?? self::MESSAGES[$code] ?? self::MESSAGES['invalid'];
        foreach ($params as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }

        return new ValidationError($message, $code);
    }

    /**
     * A submitted single value as a trimmed string, or null when empty.
     * Arrays and other shapes are rejected.
     */
    protected function stringValue(mixed $value, bool $strip = true): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw $this->error('invalid');
        }
        $text = (string) $value;
        if ($strip) {
            $text = trim($text);
        }

        return $text === '' ? null : $text;
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\Widget;

/**
 * Text. Leading and trailing spaces are removed unless `strip` is false;
 * an empty value becomes `emptyValue` ('' by default).
 */
class CharField extends Field
{
    protected const MESSAGES = [
        'max_length' => 'Ensure this value has at most {max} characters (it has {length}).',
        'min_length' => 'Ensure this value has at least {min} characters (it has {length}).',
        'null_characters' => 'Null characters are not allowed.',
    ];

    public function __construct(
        public ?int $maxLength = null,
        public ?int $minLength = null,
        public bool $strip = true,
        public ?string $emptyValue = '',
        mixed ...$options,
    ) {
        parent::__construct(...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        $text = $this->stringValue($value, $this->strip);
        if ($text !== null && str_contains($text, "\0")) {
            throw $this->error('null_characters');
        }

        return $text ?? $this->emptyValue;
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (!is_string($value) || $value === '') {
            return;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($this->maxLength !== null && $length > $this->maxLength) {
            throw $this->error('max_length', ['max' => $this->maxLength, 'length' => $length]);
        }
        if ($this->minLength !== null && $length < $this->minLength) {
            throw $this->error('min_length', ['min' => $this->minLength, 'length' => $length]);
        }
    }

    public function widgetAttrs(Widget $widget): array
    {
        return $widget->isHidden ? [] : ['maxlength' => $this->maxLength, 'minlength' => $this->minLength];
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\Textarea;
use BrewPHP\Forms\Widgets\Widget;

/**
 * JSON text, cleaned to the decoded PHP value (arrays, not objects).
 */
class JsonField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid JSON.',
    ];

    public function toPhp(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->error('invalid');
        }
    }

    public function prepareValue(mixed $value): mixed
    {
        return $value === null || is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function defaultWidget(): Widget
    {
        return new Textarea();
    }
}

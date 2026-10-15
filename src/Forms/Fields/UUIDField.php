<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

/**
 * A UUID, cleaned to the lower-case "8-4-4-4-12" form.
 */
class UUIDField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid UUID.',
    ];

    public function toPhp(mixed $value): mixed
    {
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        $hex = strtolower(str_replace(['-', '{', '}'], '', $text));
        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1) {
            throw $this->error('invalid');
        }

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

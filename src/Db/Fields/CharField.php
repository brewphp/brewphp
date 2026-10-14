<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * A short string (varchar). For long text use TextField.
 */
class CharField extends Field
{
    public function __construct(public readonly int $maxLength = 255, mixed ...$options)
    {
        parent::__construct(...$options);
    }

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('varchar', $this->maxLength);
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value instanceof \BackedEnum || $this->enumClass() !== null) {
            return parent::toPhp($value);
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new ValidationError(sprintf('Expected text, got %s.', get_debug_type($value)), 'invalid');
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($length > $this->maxLength) {
                throw new ValidationError(sprintf('Ensure this value has at most %d characters (it has %d).', $this->maxLength, $length), 'max_length');
            }
        }
    }

    protected function emptyValue(): mixed
    {
        return '';
    }
}

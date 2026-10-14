<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * Any JSON-encodable value (arrays, strings, numbers, booleans), stored as
 * JSON text (`json` on MySQL, `jsonb` on PostgreSQL). Arrays come back as
 * associative arrays.
 *
 * Use a closure for a mutable default: `new JsonField(default: fn () => [])`.
 */
class JsonField extends Field
{
    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('json');
    }

    public function fromDb(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    public function toDb(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new ValidationError('Value must be valid JSON: ' . $e->getMessage(), 'invalid');
        }
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        $this->toDb($value);
    }
}

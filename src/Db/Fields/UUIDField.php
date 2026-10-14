<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;

/**
 * A UUID, as a lowercase string with dashes. Stored as `uuid` on
 * PostgreSQL and `char(36)` elsewhere. For a random UUID primary key:
 *
 *     'id' => new UUIDField(primaryKey: true, default: UUIDField::uuid4(...)),
 */
class UUIDField extends Field
{
    /**
     * A random (version 4) UUID.
     */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('uuid');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $hex = is_string($value) ? strtolower(str_replace(['-', '{', '}'], '', trim($value))) : '';
        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1) {
            throw new ValidationError(sprintf('"%s" is not a valid UUID.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
        }

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    public function toDb(mixed $value): mixed
    {
        return $value === null ? null : $this->toPhp($value);
    }
}

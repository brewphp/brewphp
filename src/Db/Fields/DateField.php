<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;

/**
 * A date. The PHP value is a `DateTimeImmutable` at midnight; strings like
 * "2026-10-14" are accepted and converted.
 *
 * `autoNow: true` sets the field to today on every save, `autoNowAdd: true`
 * only when the object is created. Both make the field non-editable.
 */
class DateField extends Field
{
    /** The format stored in the database. */
    protected const FORMAT = 'Y-m-d';

    public function __construct(public readonly bool $autoNow = false, public readonly bool $autoNowAdd = false, mixed ...$options)
    {
        if ($autoNow || $autoNowAdd) {
            $options += ['editable' => false, 'blank' => true];
        }
        parent::__construct(...$options);
    }

    public function columnType(Grammar $grammar): string
    {
        return $grammar->type('date');
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $this->normalize(\DateTimeImmutable::createFromInterface($value));
        }
        if (is_string($value)) {
            try {
                return $this->normalize(new \DateTimeImmutable($value));
            } catch (\Exception) {
                // Reported below.
            }
        }

        throw new ValidationError(sprintf('"%s" value has an invalid date format.', is_scalar($value) ? (string) $value : get_debug_type($value)), 'invalid');
    }

    public function toDb(mixed $value): mixed
    {
        $value = $this->toPhp($value);

        return $value instanceof \DateTimeInterface ? $value->format(static::FORMAT) : null;
    }

    public function preSave(Model $model, bool $adding): mixed
    {
        if ($this->autoNow || ($this->autoNowAdd && $adding)) {
            $model->setAttribute($this->attname, $this->normalize(new \DateTimeImmutable()));
        }

        return parent::preSave($model, $adding);
    }

    protected function normalize(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime(0, 0);
    }
}

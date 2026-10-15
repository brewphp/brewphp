<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\DateInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A date, cleaned to a DateTimeImmutable at midnight. Accepted formats are
 * `$inputFormats` (ISO "2026-10-15" by default, as browsers send it).
 */
class DateField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid date.',
    ];

    /** @var list<string> */
    protected const FORMATS = ['Y-m-d'];

    /**
     * @param list<string>|null $inputFormats DateTimeImmutable::createFromFormat() formats
     */
    public function __construct(public ?array $inputFormats = null, mixed ...$options)
    {
        parent::__construct(...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $this->normalize(\DateTimeImmutable::createFromInterface($value));
        }
        $text = $this->stringValue($value);
        if ($text === null) {
            return null;
        }
        foreach ($this->inputFormats ?? static::FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $this->normalize($date);
            }
        }

        throw $this->error('invalid');
    }

    protected function normalize(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime(0, 0);
    }

    protected function defaultWidget(): Widget
    {
        return new DateInput();
    }
}

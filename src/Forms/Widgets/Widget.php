<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

/**
 * How a form field is shown as HTML and read back from submitted data.
 *
 *     new Forms\CharField(widget: new Textarea(['rows' => 4]))
 *
 * `$attrs` are extra HTML attributes; they are escaped when rendered.
 */
abstract class Widget
{
    /** Whether the widget is an <input type="hidden">. */
    public bool $isHidden = false;

    /** Whether forms using it must be sent as multipart/form-data. */
    public bool $needsMultipart = false;

    /** The format of date and time values (DateTimeInterface::format()). */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @param array<string, mixed> $attrs
     */
    public function __construct(public array $attrs = [])
    {
    }

    /**
     * The HTML of the widget.
     *
     * @param array<string, mixed> $attrs attributes added by the form (id, required...)
     */
    abstract public function render(string $name, mixed $value, array $attrs = []): string;

    /**
     * The submitted value for this widget, from the form data and files.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $files
     */
    public function valueFromData(array $data, array $files, string $name): mixed
    {
        return $data[$name] ?? null;
    }

    /**
     * Whether the submitted data has no value for this widget.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $files
     */
    public function valueOmittedFromData(array $data, array $files, string $name): bool
    {
        return !array_key_exists($name, $data);
    }

    /**
     * The value as text for the HTML.
     */
    public function formatValue(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format($this->dateFormat),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * The id a <label for="..."> should point to.
     */
    public function idForLabel(string $id): string
    {
        return $id;
    }

    /**
     * Whether the browser's `required` attribute makes sense.
     */
    public function useRequiredAttribute(mixed $initial): bool
    {
        return !$this->isHidden;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function buildAttrs(array $extra): array
    {
        return array_replace($this->attrs, $extra);
    }
}

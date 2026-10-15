<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Widgets;

use BrewPHP\Forms\Html;

/**
 * <input type="file">. The value comes from the uploaded files, and the
 * form must be sent as multipart/form-data (`form.is_multipart`).
 */
class FileInput extends Widget
{
    public bool $needsMultipart = true;

    public function render(string $name, mixed $value, array $attrs = []): string
    {
        return Html::tag('input', $this->buildAttrs(['type' => 'file', 'name' => $name, ...$attrs]));
    }

    public function valueFromData(array $data, array $files, string $name): mixed
    {
        return $files[$name] ?? null;
    }

    public function valueOmittedFromData(array $data, array $files, string $name): bool
    {
        return !array_key_exists($name, $files);
    }

    public function useRequiredAttribute(mixed $initial): bool
    {
        // A field that already has a file need not be uploaded again.
        return parent::useRequiredAttribute($initial) && ($initial === null || $initial === '');
    }
}

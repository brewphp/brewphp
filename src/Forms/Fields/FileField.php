<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\FileInput;
use BrewPHP\Forms\Widgets\Widget;
use BrewPHP\Http\UploadedFile;

/**
 * An uploaded file, cleaned to an UploadedFile.
 *
 *     new FileField(maxSize: 2 * 1024 * 1024, extensions: ['pdf', 'png', 'jpg'])
 *
 * The extension and the size are checked; the browser's MIME type and file
 * name are not trusted. Never serve uploads from a folder where PHP runs,
 * and store them under a new name (`$file->hashName()`).
 */
class FileField extends Field
{
    protected const MESSAGES = [
        'invalid' => 'No file was submitted. Check the encoding type on the form.',
        'upload_error' => 'The file could not be uploaded: {reason}',
        'empty' => 'The submitted file is empty.',
        'max_size' => 'Ensure this file is at most {max} bytes (it is {size}).',
        'extension' => 'File extension “{extension}” is not allowed. Allowed extensions are: {allowed}.',
    ];

    /**
     * @param list<string>|null $extensions allowed extensions, lower-case, without dots
     */
    public function __construct(public ?int $maxSize = null, public ?array $extensions = null, mixed ...$options)
    {
        parent::__construct(...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!$value instanceof UploadedFile) {
            throw $this->error('invalid');
        }
        if ($value->error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (!$value->isValid()) {
            throw $this->error('upload_error', ['reason' => $value->errorMessage()]);
        }

        return $value;
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (!$value instanceof UploadedFile) {
            return;
        }
        if ($value->size === 0) {
            throw $this->error('empty');
        }
        if ($this->maxSize !== null && $value->size > $this->maxSize) {
            throw $this->error('max_size', ['max' => $this->maxSize, 'size' => $value->size]);
        }
        if ($this->extensions !== null) {
            $extension = strtolower($value->clientExtension());
            if (!in_array($extension, $this->extensions, true)) {
                throw $this->error('extension', ['extension' => $extension, 'allowed' => implode(', ', $this->extensions)]);
            }
        }
    }

    public function widgetAttrs(Widget $widget): array
    {
        return $this->extensions !== null ? ['accept' => implode(',', array_map(static fn (string $e): string => '.' . $e, $this->extensions))] : [];
    }

    protected function defaultWidget(): Widget
    {
        return new FileInput();
    }
}

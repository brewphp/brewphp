<?php

declare(strict_types=1);

namespace BrewPHP\Core;

/**
 * Invalid data, with one or more human-readable messages.
 *
 * Shared by model validation (`$model->fullClean()`) and, later, forms.
 * Messages can belong to a field or to the whole object:
 *
 *     throw new ValidationError('Enter a valid email address.');
 *     throw new ValidationError(['title' => 'This field cannot be blank.']);
 *     throw new ValidationError(['title' => ['Too short.', 'Not unique.'], '__all__' => 'Dates overlap.']);
 */
class ValidationError extends \RuntimeException
{
    /** The key of messages that do not belong to one field (Django's NON_FIELD_ERRORS). */
    public const NON_FIELD = '__all__';

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param string|array<int|string, string|list<string>> $messages a message, a list of messages, or messages per field
     */
    public function __construct(string|array $messages, public readonly string $errorCode = 'invalid')
    {
        foreach (is_string($messages) ? [$messages] : $messages as $key => $value) {
            $field = is_int($key) ? self::NON_FIELD : $key;
            foreach ((array) $value as $message) {
                $this->errors[$field][] = (string) $message;
            }
        }

        parent::__construct(implode(' ', $this->messages()));
    }

    /**
     * Messages per field name; messages for the whole object use the key `__all__`.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Every message, as a flat list.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_merge(...array_values($this->errors));
    }

    public function hasField(string $field): bool
    {
        return isset($this->errors[$field]);
    }
}

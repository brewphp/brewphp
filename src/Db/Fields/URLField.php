<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;

/**
 * A web address starting with http://, https://, ftp:// or ftps://.
 */
class URLField extends CharField
{
    public function __construct(int $maxLength = 200, mixed ...$options)
    {
        parent::__construct($maxLength, ...$options);
    }

    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if (!is_string($value) || $value === '') {
            return;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'ftp', 'ftps'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new ValidationError('Enter a valid URL.', 'invalid');
        }
    }
}

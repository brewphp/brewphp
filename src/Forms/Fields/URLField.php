<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Forms\Widgets\URLInput;
use BrewPHP\Forms\Widgets\Widget;

/**
 * A web address. Only `http` and `https` (or `$schemes`) are accepted, so a
 * "javascript:" link can never be saved and shown later. Without a scheme,
 * "https://" is added.
 */
class URLField extends CharField
{
    protected const MESSAGES = [
        'invalid' => 'Enter a valid URL.',
    ] + parent::MESSAGES;

    /**
     * @param list<string> $schemes
     */
    public function __construct(?int $maxLength = 2048, public array $schemes = ['http', 'https'], mixed ...$options)
    {
        parent::__construct($maxLength, ...$options);
    }

    public function toPhp(mixed $value): mixed
    {
        $value = parent::toPhp($value);
        if (is_string($value) && $value !== '' && !str_contains($value, '://') && preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) !== 1) {
            $value = 'https://' . $value;
        }

        return $value;
    }

    public function validate(mixed $value): void
    {
        parent::validate($value);
        if (!is_string($value) || $value === '') {
            return;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, $this->schemes, true) || !is_string($host) || $host === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw $this->error('invalid');
        }
    }

    protected function defaultWidget(): Widget
    {
        return new URLInput();
    }
}

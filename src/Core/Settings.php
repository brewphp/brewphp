<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Support\Arr;
use BrewPHP\Templates\ContextProcessors;

/**
 * Project settings: the framework defaults merged with `config/settings.php`.
 *
 * Keys are UPPER_CASE like Django. Nested values can be read with dots:
 * `$settings->get('DATABASES.default.ENGINE')`.
 */
final class Settings
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_replace(self::defaults(), $values);
    }

    /**
     * Load settings from a PHP file that returns an array.
     */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \UnexpectedValueException(sprintf('Settings file "%s" must return an array.', $file));
        }

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    /**
     * The defaults every project starts from.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'DEBUG' => false,
            'SECRET_KEY' => '',
            'ALLOWED_HOSTS' => [],
            'INSTALLED_APPS' => [],
            'MIDDLEWARE' => [],
            'ROOT_URLCONF' => 'config/urls.php',
            'APPEND_SLASH' => true,
            'TEMPLATES' => [
                'DIRS' => ['templates'],
                'CACHE' => true,
                'STRICT' => false,
                'CONTEXT_PROCESSORS' => [[ContextProcessors::class, 'request']],
            ],
            'APP_URL' => 'http://localhost',
            'TIME_ZONE' => 'UTC',
            'LANGUAGE' => 'en',
            'STATIC_URL' => '/static/',
            'MEDIA_URL' => '/media/',
            'STORAGE_PATH' => 'storage',
            'COMMANDS' => [],
            'DATABASES' => [
                'default' => [
                    'ENGINE' => 'sqlite',
                    'NAME' => 'storage/db.sqlite3',
                ],
            ],
            'SECURE_CONTENT_TYPE_NOSNIFF' => true,
            'SECURE_FRAME_OPTIONS' => 'DENY',
            'SECURE_REFERRER_POLICY' => 'same-origin',
            'SECURE_CROSS_ORIGIN_OPENER_POLICY' => 'same-origin',
            'SECURE_HSTS_SECONDS' => 0,
            'SECURE_HSTS_INCLUDE_SUBDOMAINS' => false,
            'SECURE_HSTS_PRELOAD' => false,
            'SECURE_CSP' => null,
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->values, $key);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->values, $key, $value);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [$value];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}

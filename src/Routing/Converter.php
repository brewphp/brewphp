<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

use Closure;

/**
 * A path converter: the `int` in `<int:id>`.
 *
 * It says which text matches (a regex), how to turn the matched text into
 * a PHP value, and how to turn a value back into URL text when reversing.
 *
 *     Router::registerConverter('year', new Converter('[0-9]{4}', fn (string $v): int => (int) $v));
 */
final class Converter
{
    private ?Closure $toPhp;

    private ?Closure $toUrl;

    /**
     * @param string $regex regex for the value, without anchors or groups
     * @param (callable(string): mixed)|null $toPhp
     * @param (callable(mixed): string)|null $toUrl
     */
    public function __construct(
        public readonly string $regex,
        ?callable $toPhp = null,
        ?callable $toUrl = null,
    ) {
        $this->toPhp = $toPhp !== null ? Closure::fromCallable($toPhp) : null;
        $this->toUrl = $toUrl !== null ? Closure::fromCallable($toUrl) : null;
    }

    /**
     * The built-in converters: int, str, slug, uuid and path.
     *
     * @return array<string, Converter>
     */
    public static function defaults(): array
    {
        return [
            'int' => new self('[0-9]+', static fn (string $v): int => (int) $v),
            'str' => new self('[^/]+'),
            'slug' => new self('[-a-zA-Z0-9_]+'),
            'uuid' => new self('[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'),
            'path' => new self('.+', null, static fn (mixed $v): string => implode('/', array_map(
                'rawurlencode',
                explode('/', self::stringify($v)),
            ))),
        ];
    }

    public function toPhp(string $value): mixed
    {
        return $this->toPhp !== null ? ($this->toPhp)($value) : $value;
    }

    public function toUrl(mixed $value): string
    {
        return $this->toUrl !== null ? ($this->toUrl)($value) : rawurlencode(self::stringify($value));
    }

    private static function stringify(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf('Cannot put a value of type %s in a URL.', get_debug_type($value)));
    }
}

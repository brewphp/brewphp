<?php

declare(strict_types=1);

namespace BrewPHP\Routing;

use BrewPHP\Core\ImproperlyConfigured;

/**
 * One URL pattern mapped to a view. Create routes with the `path()` and
 * `re_path()` helpers in a `urls.php` file.
 *
 * A route is made of segments: the pattern given to `path()` plus the
 * prefixes of any `include_urls()` or `group()` it sits in. Each segment is
 * either a path pattern (`posts/<int:id>/`) or a regex (`^(?P<year>[0-9]{4})/$`).
 */
final class Route
{
    private ?string $regex = null;

    /** Reverse template like "posts/{id}/", or null if not reversible. */
    private ?string $template = null;

    /** @var array<string, string> parameter name => converter name */
    private array $parameters = [];

    /**
     * @param list<array{0: string, 1: bool}> $segments [pattern, isRegex] pairs
     * @param list<string> $methods allowed HTTP methods; empty allows all
     * @param list<mixed> $middleware
     */
    public function __construct(
        private readonly array $segments,
        public readonly mixed $view,
        public readonly ?string $name = null,
        public readonly array $methods = [],
        public readonly array $middleware = [],
    ) {
    }

    /**
     * @param list<string> $methods
     * @param list<mixed> $middleware
     */
    public static function path(string $pattern, mixed $view, ?string $name = null, array $methods = [], array $middleware = []): self
    {
        return new self([[ltrim($pattern, '/'), false]], $view, $name, self::normalizeMethods($methods), $middleware);
    }

    /**
     * @param list<string> $methods
     * @param list<mixed> $middleware
     */
    public static function regex(string $pattern, mixed $view, ?string $name = null, array $methods = [], array $middleware = []): self
    {
        return new self([[$pattern, true]], $view, $name, self::normalizeMethods($methods), $middleware);
    }

    /**
     * Copy of this route placed under a prefix, namespace and middleware.
     *
     * @param list<array{0: string, 1: bool}> $segments
     * @param list<mixed> $middleware
     */
    public function prefixed(array $segments, ?string $namespace = null, array $middleware = []): self
    {
        $name = $this->name !== null && $namespace !== null ? $namespace . ':' . $this->name : $this->name;

        return new self(
            array_merge($segments, $this->segments),
            $this->view,
            $name,
            $this->methods,
            array_merge($middleware, $this->middleware),
        );
    }

    /**
     * The pattern as written, e.g. "blog/posts/<int:id>/".
     */
    public function pattern(): string
    {
        $pattern = '';
        foreach ($this->segments as [$segment, $isRegex]) {
            $pattern .= $isRegex ? self::trimAnchors($segment) : $segment;
        }

        return $pattern;
    }

    public function allowsMethod(string $method): bool
    {
        if ($this->methods === []) {
            return true;
        }

        return in_array($method, $this->methods, true) || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * Match a request path (without the leading "/"). Returns the converted
     * parameters, or null when the path does not match.
     *
     * @param array<string, Converter> $converters
     * @return array<string, mixed>|null
     */
    public function match(string $path, array $converters): ?array
    {
        $this->compile($converters);
        if (!preg_match((string) $this->regex, $path, $matches)) {
            return null;
        }

        $parameters = [];
        foreach ($matches as $key => $value) {
            if (is_string($key) && $value !== '') {
                $converter = $this->parameters[$key] ?? null;
                $parameters[$key] = $converter !== null ? $converters[$converter]->toPhp($value) : $value;
            }
        }

        return $parameters;
    }

    /**
     * Build the URL path for this route, e.g. "/posts/5/".
     *
     * @param array<string|int, mixed> $parameters named, or positional in pattern order
     * @param array<string, Converter> $converters
     */
    public function reverse(array $parameters, array $converters): string
    {
        $this->compile($converters);
        $label = $this->name ?? $this->pattern();

        if ($this->template === null) {
            throw new NoReverseMatch(sprintf('Route "%s" uses a regex that is too complex to reverse.', $label));
        }

        preg_match_all('/\{(\w+)\}/', $this->template, $found);
        $expected = $found[1];
        if ($parameters !== [] && array_is_list($parameters) && count($parameters) === count($expected)) {
            // Positional values, in the order of the pattern (used by {% url %}).
            $parameters = array_combine($expected, $parameters);
        }
        $missing = array_diff($expected, array_keys($parameters));
        $unknown = array_diff(array_keys($parameters), $expected);
        if ($missing !== [] || $unknown !== []) {
            throw new NoReverseMatch(sprintf(
                'Reverse for "%s" expects parameters [%s], got [%s].',
                $label,
                implode(', ', $expected),
                implode(', ', array_keys($parameters)),
            ));
        }

        $path = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($parameters, $converters): string {
            $converter = $this->parameters[$m[1]] ?? null;

            return $converter !== null
                ? $converters[$converter]->toUrl($parameters[$m[1]])
                : (new Converter('.+'))->toUrl($parameters[$m[1]]);
        }, $this->template);

        if (!preg_match((string) $this->regex, $path)) {
            throw new NoReverseMatch(sprintf('Reverse for "%s" with these parameters gives "%s", which does not match the pattern.', $label, $path));
        }

        return '/' . $path;
    }

    /**
     * @param array<string, Converter> $converters
     */
    private function compile(array $converters): void
    {
        if ($this->regex !== null) {
            return;
        }

        $regex = '';
        $template = '';
        foreach ($this->segments as [$segment, $isRegex]) {
            if ($isRegex) {
                $inner = str_replace('#', '\#', self::trimAnchors($segment));
                $regex .= $inner;
                $template = $template === null ? null : self::regexTemplate($inner, $template);
            } else {
                [$segmentRegex, $segmentTemplate] = $this->compilePath($segment, $converters);
                $regex .= $segmentRegex;
                $template = $template === null ? null : $template . $segmentTemplate;
            }
        }

        $this->regex = '#^' . $regex . '$#u';
        $this->template = $template;
    }

    /**
     * @param array<string, Converter> $converters
     * @return array{0: string, 1: string}
     */
    private function compilePath(string $pattern, array $converters): array
    {
        $regex = '';
        $template = '';
        $offset = 0;
        preg_match_all('/<(?:(?<converter>[A-Za-z_]\w*):)?(?<name>[A-Za-z_]\w*)>/', $pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $position] = $match[0];
            $name = $match['name'][0];
            $converter = $match['converter'][0] !== '' ? $match['converter'][0] : 'str';

            if (!isset($converters[$converter])) {
                throw new ImproperlyConfigured(sprintf('Unknown path converter "%s" in route "%s".', $converter, $pattern));
            }
            if (isset($this->parameters[$name])) {
                throw new ImproperlyConfigured(sprintf('Route "%s" uses the parameter "%s" twice.', $this->pattern(), $name));
            }
            $this->parameters[$name] = $converter;

            $literal = substr($pattern, $offset, $position - $offset);
            $regex .= preg_quote($literal, '#') . '(?P<' . $name . '>' . $converters[$converter]->regex . ')';
            $template .= $literal . '{' . $name . '}';
            $offset = $position + strlen($whole);
        }

        $rest = substr($pattern, $offset);
        if (str_contains($rest, '<') || str_contains($rest, '>')) {
            throw new ImproperlyConfigured(sprintf('Route "%s" has an unclosed or invalid "<...>" parameter.', $pattern));
        }

        return [$regex . preg_quote($rest, '#'), $template . $rest];
    }

    /**
     * Turn a simple regex such as `articles/(?P<year>[0-9]{4})/` into the
     * template `articles/{year}/`. Returns null when the regex has other
     * syntax that cannot be reversed.
     */
    private static function regexTemplate(string $regex, string $prefix): ?string
    {
        $template = '';
        $length = strlen($regex);
        for ($i = 0; $i < $length; $i++) {
            if (substr($regex, $i, 4) === '(?P<') {
                $close = strpos($regex, '>', $i);
                if ($close === false) {
                    return null;
                }
                $template .= '{' . substr($regex, $i + 4, $close - $i - 4) . '}';
                // Skip to the matching closing parenthesis.
                $depth = 0;
                for (; $i < $length; $i++) {
                    if ($regex[$i] === '\\') {
                        $i++;
                    } elseif ($regex[$i] === '(') {
                        $depth++;
                    } elseif ($regex[$i] === ')' && --$depth === 0) {
                        break;
                    }
                }
            } elseif ($regex[$i] === '\\' && $i + 1 < $length) {
                $template .= $regex[++$i];
            } elseif (str_contains('()[]{}?*+|^$.', $regex[$i])) {
                return null;
            } else {
                $template .= $regex[$i];
            }
        }

        return $prefix . $template;
    }

    private static function trimAnchors(string $regex): string
    {
        if (str_starts_with($regex, '^')) {
            $regex = substr($regex, 1);
        }
        if (str_ends_with($regex, '$') && !str_ends_with($regex, '\$')) {
            $regex = substr($regex, 0, -1);
        }

        return $regex;
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private static function normalizeMethods(array $methods): array
    {
        return array_values(array_unique(array_map('strtoupper', $methods)));
    }
}

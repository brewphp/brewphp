<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Core\AltersData;
use BrewPHP\Core\Application;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Model;
use BrewPHP\Db\QuerySet;
use BrewPHP\Routing\NoReverseMatch;
use BrewPHP\Support\Str;

/**
 * The helpers that compiled templates call (`$r->...` in the generated
 * code): variable lookup, filters, escaping, comparisons, loops, blocks
 * and includes. One Runtime is created per render; it holds the blocks
 * of the template chain and the state of `{% cycle %}` tags.
 *
 * The static methods are also handy in your own filters and tags.
 */
final class Runtime
{
    /** @var array<string, list<\Closure(Context, Runtime): void>> blocks by name, most derived first */
    private array $blocks = [];

    /** @var array<string, int> */
    private array $cycles = [];

    /** @var array<string, bool> */
    private static array $methods = [];

    public function __construct(private readonly Engine $engine)
    {
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    // Variables -----------------------------------------------------------------

    /**
     * Resolve `name.attr.0`. Missing values give null, or an error in
     * strict mode unless $lenient (used by `{% if %}` and `|default`).
     *
     * @param list<string|int> $path
     */
    public function lookup(Context $context, string $name, array $path = [], bool $lenient = false): mixed
    {
        if (!array_key_exists($name, $context->vars)) {
            return $this->missing($name, $lenient);
        }
        $value = $context->vars[$name];
        if ($value instanceof \Closure) {
            $value = $value();
        }
        foreach ($path as $i => $key) {
            $value = self::attribute($value, $key, $found);
            if (!$found) {
                return $this->missing($name . '.' . implode('.', array_slice($path, 0, $i + 1)), $lenient);
            }
            if ($value instanceof \Closure) {
                $value = $value();
            }
        }

        return $value;
    }

    /**
     * One step of a variable lookup, in this order: array key (or
     * ArrayAccess offset), public property, `__get` (when `__isset` says
     * it exists), zero-argument public method, model field. `has_next`
     * also tries `hasNext`. Methods marked #[AltersData] are never called.
     * Arrays also have `items` (a list of [key, value] pairs) and lists or
     * iterables accept an index: `posts.0`.
     *
     * @param-out bool $found whether the attribute exists
     */
    public static function attribute(mixed $target, string|int $key, ?bool &$found = null): mixed
    {
        $found = true;
        if (is_array($target)) {
            if (array_key_exists($key, $target)) {
                return $target[$key];
            }
            if ($key === 'items') {
                return array_map(static fn (mixed $k, mixed $v): array => [$k, $v], array_keys($target), $target);
            }
        } elseif (is_object($target)) {
            if ($target instanceof \ArrayAccess && $target->offsetExists($key)) {
                return $target[$key];
            }
            if (is_string($key)) {
                $names = array_unique([$key, Str::camel($key)]);
                foreach ($names as $name) {
                    if (array_key_exists($name, get_object_vars($target))) {
                        return $target->$name;
                    }
                    if (!$target instanceof Model && method_exists($target, '__isset') && isset($target->$name)) {
                        return $target->$name;
                    }
                }
                foreach ($names as $name) {
                    if (self::isCallable($target, $name)) {
                        return $target->$name();
                    }
                }
                if ($target instanceof Model) {
                    try {
                        return $target->__get($key);
                    } catch (FieldError) {
                    }
                }
            } elseif ($target instanceof \Traversable) {
                $items = $target instanceof QuerySet ? $target->toArray() : iterator_to_array($target, false);
                if (array_key_exists($key, $items)) {
                    return $items[$key];
                }
            }
        }
        $found = false;

        return null;
    }

    // Output --------------------------------------------------------------------

    /**
     * A value as HTML-escaped text (SafeString values are left alone).
     */
    public static function escape(mixed $value): string
    {
        return $value instanceof SafeString ? (string) $value : self::escapeHtml(self::toString($value));
    }

    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A value as text: null is '', booleans are "True"/"False", dates use
     * "Y-m-d H:i:s", enums their value. Arrays and objects that are not
     * Stringable cannot be printed.
     */
    public static function toString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'True' : 'False',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => throw new TemplateError(sprintf('Cannot print a value of type %s.', get_debug_type($value))),
        };
    }

    /**
     * Apply a filter by name.
     */
    public function filter(string $name, bool $autoescape, mixed $value, mixed ...$arguments): mixed
    {
        $filter = $this->engine->filterDefinition($name);
        $result = $filter['needsAutoescape']
            ? ($filter['callable'])($value, ...$arguments, autoescape: $autoescape)
            : ($filter['callable'])($value, ...$arguments);

        if ($filter['isSafe'] && $value instanceof SafeString && is_string($result)) {
            return new SafeString($result);
        }

        return $result;
    }

    /**
     * Call a tag registered with Library::simpleTag().
     *
     * @param array<int|string, mixed> $arguments
     */
    public function callTag(string $name, Context $context, array $arguments): mixed
    {
        $tag = $this->engine->simpleTagDefinition($name);
        if ($tag['takesContext']) {
            array_unshift($arguments, $context);
        }

        return ($tag['callable'])(...$arguments);
    }

    // Conditions ----------------------------------------------------------------

    /**
     * Template truthiness, like Python: null, false, 0, '', empty arrays and
     * empty countables (a QuerySet or Page without items) are false; "0" is true.
     */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            $value instanceof SafeString => (string) $value !== '',
            $value instanceof \Countable => count($value) > 0,
            default => true,
        };
    }

    /**
     * `==` in templates: numbers compare by value (1 == 1.0), everything
     * else must be the same type and value; models are equal when they
     * have the same class and primary key.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        $a = $a instanceof SafeString ? (string) $a : $a;
        $b = $b instanceof SafeString ? (string) $b : $b;
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if ($a instanceof Model && $b instanceof Model) {
            return $a::class === $b::class && $a->pk !== null && $a->pk === $b->pk;
        }
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a == $b;
        }

        return $a === $b;
    }

    /**
     * `<`, `>`, `<=`, `>=`: numbers, strings and dates; other mixes are false.
     */
    public static function compare(mixed $a, string $operator, mixed $b): bool
    {
        $a = $a instanceof SafeString ? (string) $a : $a;
        $b = $b instanceof SafeString ? (string) $b : $b;
        $comparable = ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b)))
            || (is_string($a) && is_string($b))
            || ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface);
        if (!$comparable) {
            return false;
        }

        return match ($operator) {
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
            default => throw new \InvalidArgumentException(sprintf('Unknown operator "%s".', $operator)),
        };
    }

    /**
     * `needle in haystack`: a substring of a string, a value of a list or
     * iterable, or a key of an associative array (like a Python dict).
     */
    public static function contains(mixed $haystack, mixed $needle): bool
    {
        if ($haystack instanceof SafeString || is_string($haystack)) {
            return is_scalar($needle) || $needle instanceof \Stringable ? str_contains((string) $haystack, (string) $needle) : false;
        }
        if (is_array($haystack) && !array_is_list($haystack)) {
            return (is_int($needle) || is_string($needle)) && array_key_exists($needle, $haystack);
        }
        if (is_iterable($haystack)) {
            foreach ($haystack as $item) {
                if (self::equals($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Loops ---------------------------------------------------------------------

    /**
     * The rows of a `{% for %}` loop: one value per loop variable.
     * With one variable each item is its value. With several, items of a
     * list are unpacked (`for a, b in pairs`) and other arrays or iterables
     * give key and value (`for key, value in dict`).
     *
     * @return list<list<mixed>>
     */
    public static function loopRows(mixed $iterable, int $variables, bool $reversed): array
    {
        if ($iterable === null || $iterable === '') {
            return [];
        }
        if ($iterable instanceof SafeString || is_string($iterable)) {
            $iterable = preg_split('//u', (string) $iterable, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_iterable($iterable)) {
            throw new TemplateError(sprintf('Cannot loop over a value of type %s.', get_debug_type($iterable)));
        }

        $isList = is_array($iterable) ? array_is_list($iterable) : true;
        $rows = [];
        foreach ($iterable as $key => $value) {
            if ($variables === 1) {
                $rows[] = [$value];
            } elseif (!$isList || is_string($key)) {
                $rows[] = [$key, $value];
            } else {
                $values = match (true) {
                    is_array($value) => array_values($value),
                    $value instanceof \Traversable => iterator_to_array($value, false),
                    default => [$value],
                };
                if (count($values) !== $variables) {
                    throw new TemplateError(sprintf('Need %d values to unpack in the for loop, got %d.', $variables, count($values)));
                }
                $rows[] = $values;
            }
        }

        return $reversed ? array_reverse($rows) : $rows;
    }

    // Blocks and includes -------------------------------------------------------

    /**
     * @param array<string, \Closure(Context, Runtime): void> $blocks
     */
    public function addBlocks(array $blocks): void
    {
        foreach ($blocks as $name => $block) {
            $this->blocks[$name][] = $block;
        }
    }

    /**
     * Print a block: the most derived version, or the one $depth levels up.
     */
    public function renderBlock(string $name, Context $context, int $depth = 0): void
    {
        $block = $this->blocks[$name][$depth] ?? null;
        if ($block === null) {
            return;
        }
        $context->push(['block' => new BlockReference($this, $name, $depth, $context)]);
        try {
            $block($context, $this);
        } finally {
            $context->pop();
        }
    }

    public function captureBlock(string $name, Context $context, int $depth): string
    {
        ob_start();
        try {
            $this->renderBlock($name, $context, $depth);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * `{% include %}`: render another template with this context (plus
     * $extra), or with $extra only.
     *
     * @param array<string, mixed> $extra
     */
    public function include(mixed $template, Context $context, array $extra, bool $only): string
    {
        if (!$template instanceof Template) {
            $names = is_array($template) ? array_values(array_map(self::toString(...), $template)) : self::toString($template);
            $template = $this->engine->getTemplate($names);
        }
        if ($only) {
            return $template->render(new Context($extra));
        }
        $context->push($extra);
        try {
            return $template->render($context);
        } finally {
            $context->pop();
        }
    }

    // Tag helpers ---------------------------------------------------------------

    /**
     * `{% url %}`: positional or named route parameters. With $silent (the
     * `as var` form) an unknown route gives ''.
     *
     * @param list<mixed> $arguments
     * @param array<string, mixed> $keywords
     */
    public function url(mixed $name, array $arguments, array $keywords, bool $silent = false): string
    {
        if ($arguments !== [] && $keywords !== []) {
            throw new TemplateError('Do not mix positional and named arguments in {% url %}.');
        }
        try {
            return Application::getInstance()->router()->reverse(self::toString($name), $keywords !== [] ? $keywords : $arguments);
        } catch (NoReverseMatch $e) {
            if ($silent) {
                return '';
            }
            throw $e;
        }
    }

    public function staticUrl(mixed $path): string
    {
        return rtrim($this->engine->staticUrl, '/') . '/' . ltrim(self::toString($path), '/');
    }

    /**
     * The next value of a `{% cycle %}` tag.
     *
     * @param list<mixed> $values
     */
    public function cycle(string $id, array $values): mixed
    {
        $index = $this->cycles[$id] ?? 0;
        $this->cycles[$id] = $index + 1;

        return $values === [] ? null : $values[$index % count($values)];
    }

    /**
     * @param list<mixed> $values
     */
    public static function firstOf(array $values): mixed
    {
        foreach ($values as $value) {
            if (self::truthy($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Remove whitespace between HTML tags.
     */
    public static function spaceless(string $html): string
    {
        return trim((string) preg_replace('/>\s+</', '><', $html));
    }

    // Internals -----------------------------------------------------------------

    private function missing(string $path, bool $lenient): mixed
    {
        if ($this->engine->strict && !$lenient) {
            throw new VariableDoesNotExist(sprintf('Variable "%s" does not exist.', $path));
        }

        return null;
    }

    /**
     * May a template call this method? Public, not static, no required
     * parameter, not magic and not marked #[AltersData] (here or in a parent).
     */
    private static function isCallable(object $target, string $name): bool
    {
        $key = $target::class . '::' . strtolower($name);
        if (isset(self::$methods[$key])) {
            return self::$methods[$key];
        }
        $allowed = false;
        if (!str_starts_with($name, '_') && method_exists($target, $name)) {
            $method = new \ReflectionMethod($target, $name);
            $allowed = $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0;
            for ($class = new \ReflectionClass($target); $allowed && $class !== false; $class = $class->getParentClass()) {
                if ($class->hasMethod($name) && $class->getMethod($name)->getAttributes(AltersData::class) !== []) {
                    $allowed = false;
                }
            }
        }

        return self::$methods[$key] = $allowed;
    }
}

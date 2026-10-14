<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Templates\Nodes\Node;

/**
 * Writes the PHP code of a compiled template.
 *
 * Inside the generated code `$c` is the Context and `$r` the Runtime.
 * Template text and literals never become PHP code: they are always
 * written as PHP string literals with `Compiler::string()` or
 * `Compiler::literal()`, so `<?php` in a template is just text.
 *
 * Before each node the compiler writes a `// line N` comment, used to find
 * the template line of an error raised by the compiled code.
 */
final class Compiler
{
    private string $code = '';

    private ?int $lastLine = null;

    private int $counter = 0;

    public function __construct(private int $indentation = 0)
    {
    }

    /**
     * Add lines of PHP code at the current indentation.
     */
    public function write(string ...$lines): self
    {
        foreach ($lines as $line) {
            $this->code .= str_repeat('    ', $this->indentation) . $line . "\n";
        }

        return $this;
    }

    public function indent(): self
    {
        $this->indentation++;

        return $this;
    }

    public function outdent(): self
    {
        $this->indentation = max(0, $this->indentation - 1);

        return $this;
    }

    /**
     * Note the template line of the code that follows.
     */
    public function line(int $line): self
    {
        if ($line > 0 && $line !== $this->lastLine) {
            $this->write('// line ' . $line);
            $this->lastLine = $line;
        }

        return $this;
    }

    public function subcompile(Node $node): self
    {
        $node->compile($this);

        return $this;
    }

    /**
     * A PHP variable name that is unique in this compiled function, e.g. "$_loop3".
     */
    public function variable(string $prefix = 'v'): string
    {
        return '$_' . $prefix . ++$this->counter;
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * A PHP string literal for any string, always on one line. Strings with
     * control characters use double quotes with every `\`, `"`, `$` and
     * control character escaped.
     */
    public static function string(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
        }
        $escaped = strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']);

        return '"' . preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $m): string => sprintf('\\x%02X', ord($m[0])),
            $escaped,
        ) . '"';
    }

    /**
     * A PHP literal for null, a bool, a number, a string or an array of those.
     */
    public static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => var_export($value, true),
            is_string($value) => self::string($value),
            is_array($value) && array_is_list($value) => '[' . implode(', ', array_map(self::literal(...), $value)) . ']',
            is_array($value) => '[' . implode(', ', array_map(
                static fn (mixed $key, mixed $item): string => self::literal($key) . ' => ' . self::literal($item),
                array_keys($value),
                $value,
            )) . ']',
            default => throw new \InvalidArgumentException(sprintf('Cannot compile a %s literal.', get_debug_type($value))),
        };
    }

    /**
     * A PHP array expression from already compiled expressions.
     *
     * @param array<int|string, string> $expressions
     */
    public static function array(array $expressions): string
    {
        $items = [];
        foreach ($expressions as $key => $expression) {
            $items[] = is_int($key) ? $expression : self::string($key) . ' => ' . $expression;
        }

        return '[' . implode(', ', $items) . ']';
    }
}

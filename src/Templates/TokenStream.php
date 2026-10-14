<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * The small tokens inside a `{{ ... }}` or a tag: strings, numbers,
 * names (`post.author.name`) and operators (`|`, `:`, `==`, `(`...).
 *
 * Tag compilers read their arguments from it:
 *
 *     $stream = $parser->arguments($token);
 *     $value = $parser->filterExpression($stream);
 *     if ($stream->nextIfName('as')) { $target = $stream->expectName(); }
 *     $stream->expectEnd();
 */
final class TokenStream
{
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const NAME = 'name';
    public const OPERATOR = 'operator';

    private const PATTERN = '/\G\s*(?:'
        . '(?<string>"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')'
        . '|(?<number>-?\d+(?:\.\d+)?)(?![\w.])'
        . '|(?<name>[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)'
        . '|(?<operator>==|!=|<=|>=|[<>|:,=()])'
        . ')/A';

    /** @var list<array{0: string, 1: string}> type and value */
    private array $tokens = [];

    private int $position = 0;

    public function __construct(public readonly string $source)
    {
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (trim(substr($source, $offset)) === '') {
                break;
            }
            if (preg_match(self::PATTERN, $source, $match, 0, $offset) !== 1) {
                $rest = ltrim(substr($source, $offset));
                throw new TemplateSyntaxError(sprintf('Could not parse the remainder "%s" of "%s".', $rest, $source));
            }
            foreach ([self::STRING, self::NUMBER, self::NAME, self::OPERATOR] as $type) {
                if (isset($match[$type]) && $match[$type] !== '') {
                    $this->tokens[] = [$type, $match[$type]];
                    break;
                }
            }
            $offset += strlen($match[0]);
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function peek(int $offset = 0): ?array
    {
        return $this->tokens[$this->position + $offset] ?? null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function next(): array
    {
        return $this->tokens[$this->position++] ?? throw new TemplateSyntaxError(sprintf('Unexpected end of "%s".', $this->source));
    }

    public function isEnd(): bool
    {
        return $this->position >= count($this->tokens);
    }

    /**
     * Is the next token of this type (and value)?
     */
    public function test(string $type, ?string $value = null, int $offset = 0): bool
    {
        $token = $this->peek($offset);

        return $token !== null && $token[0] === $type && ($value === null || $token[1] === $value);
    }

    /**
     * Consume the next token when it matches and return its value.
     */
    public function nextIf(string $type, ?string $value = null): ?string
    {
        return $this->test($type, $value) ? $this->next()[1] : null;
    }

    /**
     * Consume a keyword such as "as", "with" or "only" when it comes next.
     */
    public function nextIfName(string $keyword): bool
    {
        return $this->nextIf(self::NAME, $keyword) !== null;
    }

    public function expect(string $type, ?string $value = null): string
    {
        if (!$this->test($type, $value)) {
            $token = $this->peek();
            throw new TemplateSyntaxError(sprintf(
                'Expected %s in "%s", got %s.',
                $value !== null ? '"' . $value . '"' : 'a ' . $type,
                $this->source,
                $token === null ? 'the end' : '"' . $token[1] . '"',
            ));
        }

        return $this->next()[1];
    }

    /**
     * A plain name without dots, such as a variable to assign to.
     */
    public function expectName(): string
    {
        $name = $this->expect(self::NAME);
        if (str_contains($name, '.') || str_starts_with($name, '_')) {
            throw new TemplateSyntaxError(sprintf('"%s" is not a valid variable name.', $name));
        }

        return $name;
    }

    public function expectEnd(): void
    {
        if (!$this->isEnd()) {
            throw new TemplateSyntaxError(sprintf('Unexpected "%s" in "%s".', $this->next()[1], $this->source));
        }
    }
}

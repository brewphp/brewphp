<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Templates\Nodes\BlockNode;
use BrewPHP\Templates\Nodes\NodeList;
use BrewPHP\Templates\Nodes\TextNode;
use BrewPHP\Templates\Nodes\VariableNode;

/**
 * Turns the tokens of a template into nodes.
 *
 * Tag compilers (see Library::tag()) use it to read their arguments and
 * the nodes up to their end tag:
 *
 *     $body = $parser->parse(['endmytag']);
 *     $parser->nextToken(); // the {% endmytag %} token
 *
 * Expressions become PHP code strings built only from validated names
 * and escaped literals, so template source can never inject PHP.
 */
final class Parser
{
    private const LITERALS = ['True' => 'true', 'False' => 'false', 'None' => 'null', 'true' => 'true', 'false' => 'false', 'null' => 'null'];

    /** Filters that exist to handle missing values, so strict mode lets them see one. */
    private const LENIENT_FILTERS = ['default', 'default_if_none'];

    /** Is auto-escaping on for the nodes being parsed? (`{% autoescape %}` changes it.) */
    public bool $autoescape = true;

    /** @var array<string, BlockNode> every `{% block %}` of the template, by name */
    public array $blocks = [];

    /** Compiled expression of the `{% extends %}` parent, if any. */
    public ?string $parent = null;

    /** Line of the `{% extends %}` tag. */
    public int $parentLine = 0;

    /** @var array<string, array{id: string, values: string}> named `{% cycle ... as name %}` tags */
    public array $cycles = [];

    /** True once a tag or variable other than extends/load/comment was parsed. */
    public bool $hasContent = false;

    /** How many tags are being parsed around the current position (0 = top level). */
    public int $depth = 0;

    private int $position = 0;

    private int $counter = 0;

    /**
     * @param list<Token> $tokens
     * @param string $key unique key of this template (used for ids)
     */
    public function __construct(
        private readonly Engine $engine,
        public readonly string $name,
        public readonly string $key,
        private readonly array $tokens,
    ) {
    }

    /**
     * Parse nodes until one of the $until tags (which is not consumed) or
     * the end of the template.
     *
     * @param list<string> $until
     */
    public function parse(array $until = [], ?Token $opener = null): NodeList
    {
        $nodes = new NodeList();
        while (($token = $this->tokens[$this->position] ?? null) !== null) {
            $this->position++;
            try {
                $node = match ($token->type) {
                    Token::TEXT => $token->contents === '' ? null : new TextNode($token->contents),
                    Token::VARIABLE => $this->variable($token),
                    Token::COMMENT => null,
                    default => null,
                };
                if ($token->type === Token::BLOCK) {
                    $name = $token->tagName();
                    if (in_array($name, $until, true)) {
                        $this->position--;

                        return $nodes;
                    }
                    $node = $this->tag($name, $token, $until);
                }
            } catch (TemplateSyntaxError $e) {
                if ($e->templateLine === null) {
                    $e->templateLine = $token->line;
                }
                throw $e;
            }
            if ($node !== null) {
                $node->line = $node->line > 0 ? $node->line : $token->line;
                $nodes->nodes[] = $node;
            }
        }

        if ($until !== []) {
            throw TemplateSyntaxError::atLine(sprintf(
                'Unclosed tag "%s": expected %s.',
                $opener?->tagName() ?? '?',
                self::quoteList($until),
            ), $opener !== null ? $opener->line : 0);
        }

        return $nodes;
    }

    /**
     * Consume and return the next token (usually the end tag found by parse()).
     */
    public function nextToken(): Token
    {
        return $this->tokens[$this->position++] ?? throw new TemplateSyntaxError('Unexpected end of template.');
    }

    /**
     * Skip every token up to and including `{% $endTag %}` (for `{% comment %}`).
     */
    public function skipPast(string $endTag, Token $opener): void
    {
        while (($token = $this->tokens[$this->position++] ?? null) !== null) {
            if ($token->type === Token::BLOCK && $token->tagName() === $endTag) {
                return;
            }
        }
        throw TemplateSyntaxError::atLine(sprintf('Unclosed tag "%s": expected "%s".', $opener->tagName(), $endTag), $opener->line);
    }

    /**
     * The arguments of a tag as a token stream.
     */
    public function arguments(Token $token): TokenStream
    {
        return new TokenStream($token->tagArguments());
    }

    /**
     * A unique id within this template, e.g. for `{% cycle %}` state.
     */
    public function uniqueId(): string
    {
        return $this->key . '#' . ++$this->counter;
    }

    /**
     * A value with optional filters, `post.title|lower|truncatechars:20`,
     * compiled to PHP. $lenient: a missing variable is null even in strict mode.
     */
    public function filterExpression(TokenStream $stream, bool $lenient = false): string
    {
        $token = $stream->next();
        $filters = [];
        while ($stream->nextIf(TokenStream::OPERATOR, '|') !== null) {
            $name = $stream->expect(TokenStream::NAME);
            if (!$this->engine->hasFilter($name)) {
                throw new TemplateSyntaxError(sprintf('Unknown filter "%s".', $name));
            }
            $argument = null;
            if ($stream->nextIf(TokenStream::OPERATOR, ':') !== null) {
                $argument = $this->operand($stream->next(), $lenient);
            }
            $filters[] = [$name, $argument];
            $lenient = $lenient || in_array($name, self::LENIENT_FILTERS, true);
        }

        $code = $this->operand($token, $lenient);
        foreach ($filters as [$name, $argument]) {
            $code = sprintf(
                '$r->filter(%s, %s, %s%s)',
                Compiler::string($name),
                $this->autoescape ? 'true' : 'false',
                $code,
                $argument !== null ? ', ' . $argument : '',
            );
        }

        return $code;
    }

    /**
     * Parse a whole string as one filter expression.
     */
    public function compileExpression(string $source, bool $lenient = false): string
    {
        $stream = new TokenStream($source);
        if ($stream->isEnd()) {
            throw new TemplateSyntaxError('Empty expression.');
        }
        $code = $this->filterExpression($stream, $lenient);
        $stream->expectEnd();

        return $code;
    }

    /**
     * A condition for `{% if %}`, compiled to a PHP expression giving a bool.
     * Operators from lowest to highest priority: or; and; not; in, not in,
     * ==, !=, <, >, <=, >=, is, is not. Parentheses group.
     */
    public function condition(TokenStream $stream): string
    {
        $code = $this->orCondition($stream);
        $stream->expectEnd();

        return $code;
    }

    public function error(string $message, ?Token $token = null): TemplateSyntaxError
    {
        $error = new TemplateSyntaxError($message);
        $error->templateLine = $token?->line;

        return $error;
    }

    // Internals -----------------------------------------------------------------

    private function variable(Token $token): VariableNode
    {
        if ($token->contents === '') {
            throw new TemplateSyntaxError('Empty variable tag {{ }}.');
        }
        $this->hasContent = true;

        return new VariableNode($this->compileExpression($token->contents), $this->autoescape);
    }

    /**
     * @param list<string> $until
     */
    private function tag(string $name, Token $token, array $until): Nodes\Node
    {
        if ($name === '') {
            throw new TemplateSyntaxError('Empty tag {% %}.');
        }
        $compiler = $this->engine->tagCompiler($name);
        if ($compiler === null) {
            throw new TemplateSyntaxError($until !== []
                ? sprintf('Unexpected tag "%s": expected %s.', $name, self::quoteList($until))
                : sprintf('Unknown tag "%s". Is the library that defines it registered?', $name));
        }

        $this->depth++;
        try {
            $node = $compiler($this, $token);
        } finally {
            $this->depth--;
        }
        if (!in_array($name, ['extends', 'load', 'comment'], true)) {
            $this->hasContent = true;
        }
        if ($node instanceof BlockNode) {
            if (isset($this->blocks[$node->name])) {
                throw new TemplateSyntaxError(sprintf('Block "%s" appears more than once.', $node->name));
            }
            $this->blocks[$node->name] = $node;
        }

        return $node;
    }

    /**
     * A literal or a variable as PHP code.
     *
     * @param array{0: string, 1: string} $token
     */
    private function operand(array $token, bool $lenient): string
    {
        [$type, $value] = $token;

        return match ($type) {
            TokenStream::STRING => Compiler::string(self::unquote($value)),
            TokenStream::NUMBER => str_contains($value, '.') ? Compiler::literal((float) $value) : Compiler::literal((int) $value),
            TokenStream::NAME => self::LITERALS[$value] ?? $this->lookup($value, $lenient),
            default => throw new TemplateSyntaxError(sprintf('Unexpected "%s".', $value)),
        };
    }

    private function lookup(string $variable, bool $lenient): string
    {
        $parts = explode('.', $variable);
        foreach ($parts as $part) {
            if (str_starts_with($part, '_')) {
                throw new TemplateSyntaxError(sprintf('Variables and attributes may not begin with an underscore: "%s".', $variable));
            }
        }
        $name = array_shift($parts);
        $path = array_map(static fn (string $part): string|int => ctype_digit($part) ? (int) $part : $part, $parts);

        return sprintf(
            '$r->lookup($c, %s%s%s)',
            Compiler::string($name),
            $path !== [] || $lenient ? ', ' . Compiler::literal($path) : '',
            $lenient ? ', true' : '',
        );
    }

    private static function unquote(string $literal): string
    {
        // Only \\ and the quote character are escapes, so "\a\t" in a date format stays as written.
        $quote = $literal[0];

        return (string) preg_replace('/\\\\([\\\\' . $quote . '])/', '$1', substr($literal, 1, -1));
    }

    private function orCondition(TokenStream $stream): string
    {
        $code = $this->andCondition($stream);
        while ($stream->nextIfName('or')) {
            $code = sprintf('(%s || %s)', $code, $this->andCondition($stream));
        }

        return $code;
    }

    private function andCondition(TokenStream $stream): string
    {
        $code = $this->notCondition($stream);
        while ($stream->nextIfName('and')) {
            $code = sprintf('(%s && %s)', $code, $this->notCondition($stream));
        }

        return $code;
    }

    private function notCondition(TokenStream $stream): string
    {
        if ($stream->test(TokenStream::NAME, 'not') && !$stream->test(TokenStream::NAME, 'in', 1)) {
            $stream->next();

            return '!' . $this->notCondition($stream);
        }

        return $this->comparison($stream);
    }

    private function comparison(TokenStream $stream): string
    {
        if ($stream->nextIf(TokenStream::OPERATOR, '(') !== null) {
            $code = $this->orCondition($stream);
            $stream->expect(TokenStream::OPERATOR, ')');

            return $code;
        }
        if ($stream->isEnd() || $stream->test(TokenStream::OPERATOR) || $stream->test(TokenStream::NAME, 'and') || $stream->test(TokenStream::NAME, 'or')) {
            throw new TemplateSyntaxError(sprintf('Expected a value in condition "%s".', $stream->source));
        }

        $left = $this->filterExpression($stream, true);
        $operator = null;
        foreach (['==', '!=', '<', '>', '<=', '>='] as $candidate) {
            $operator ??= $stream->nextIf(TokenStream::OPERATOR, $candidate);
        }
        if ($operator !== null) {
            $right = $this->filterExpression($stream, true);

            return match ($operator) {
                '==' => sprintf('$r->equals(%s, %s)', $left, $right),
                '!=' => sprintf('!$r->equals(%s, %s)', $left, $right),
                default => sprintf('$r->compare(%s, %s, %s)', $left, Compiler::string($operator), $right),
            };
        }
        if ($stream->nextIfName('in')) {
            return sprintf('$r->contains(%s, %s)', $this->filterExpression($stream, true), $left);
        }
        if ($stream->test(TokenStream::NAME, 'not') && $stream->test(TokenStream::NAME, 'in', 1)) {
            $stream->next();
            $stream->next();

            return sprintf('!$r->contains(%s, %s)', $this->filterExpression($stream, true), $left);
        }
        if ($stream->nextIfName('is')) {
            $negate = $stream->nextIfName('not');

            return sprintf('(%s %s %s)', $left, $negate ? '!==' : '===', $this->filterExpression($stream, true));
        }

        return sprintf('$r->truthy(%s)', $left);
    }

    /**
     * @param list<string> $names
     */
    private static function quoteList(array $names): string
    {
        return implode(' or ', array_map(static fn (string $name): string => '"' . $name . '"', $names));
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Templates\Nodes\Node;
use BrewPHP\Templates\Nodes\TagNode;

/**
 * A set of filters and tags. Register one from an app's `ready()` hook:
 *
 *     public function ready(Application $app): void
 *     {
 *         $app->templates()->library(
 *             (new Library())
 *                 ->filter('shout', fn (mixed $value): string => strtoupper((string) $value) . '!')
 *                 ->simpleTag('greeting', fn (string $name): string => 'Hello, ' . $name),
 *         );
 *     }
 *
 * Libraries are global: `{% load %}` is accepted but does nothing. A name
 * registered later replaces an earlier one, built-ins included.
 */
final class Library
{
    /** @var array<string, array{callable: callable, isSafe: bool, needsAutoescape: bool}> */
    private array $filters = [];

    /** @var array<string, callable(Parser, Token): Node> */
    private array $tags = [];

    /** @var array<string, array{callable: callable, takesContext: bool}> */
    private array $simpleTags = [];

    /**
     * Add a filter: `{{ value|name }}` calls `$filter($value)`,
     * `{{ value|name:arg }}` calls `$filter($value, $arg)`.
     *
     * $isSafe: the filter adds no HTML special characters, so a SafeString
     * input gives a SafeString result. $needsAutoescape: the filter gets a
     * named `autoescape: bool` argument and must escape its input itself
     * when it is true (for filters that return HTML).
     */
    public function filter(string $name, callable $filter, bool $isSafe = false, bool $needsAutoescape = false): self
    {
        $this->filters[self::validName($name)] = ['callable' => $filter, 'isSafe' => $isSafe, 'needsAutoescape' => $needsAutoescape];

        return $this;
    }

    /**
     * Add a tag with its own compiler: a callable receiving the Parser and
     * the Token and returning a Node that writes PHP code. Use it for tags
     * with an end tag or a custom syntax; most tags only need simpleTag().
     *
     * @param callable(Parser, Token): Node $compiler
     */
    public function tag(string $name, callable $compiler): self
    {
        $this->tags[self::validName($name)] = $compiler;

        return $this;
    }

    /**
     * Add a tag that calls a function with its arguments and prints the
     * result, escaped unless it is a SafeString:
     * `{% greeting user.name punctuation="!" %}` calls
     * `$function($name, punctuation: '!')`. `{% greeting user.name as text %}`
     * stores the result in a variable instead. With $takesContext the
     * function receives the Context as its first argument.
     */
    public function simpleTag(string $name, callable $function, bool $takesContext = false): self
    {
        $name = self::validName($name);
        $this->simpleTags[$name] = ['callable' => $function, 'takesContext' => $takesContext];
        $this->tags[$name] = static function (Parser $parser, Token $token) use ($name): Node {
            $stream = $parser->arguments($token);
            $arguments = [];
            $target = null;
            while (!$stream->isEnd()) {
                if ($stream->test(TokenStream::NAME, 'as') && $stream->test(TokenStream::NAME, null, 1) && $stream->peek(2) === null) {
                    $stream->next();
                    $target = $stream->expectName();
                    break;
                }
                if ($stream->test(TokenStream::NAME) && $stream->test(TokenStream::OPERATOR, '=', 1)) {
                    $key = $stream->expectName();
                    $stream->next();
                    $arguments[$key] = $parser->filterExpression($stream);
                } else {
                    $arguments[] = $parser->filterExpression($stream);
                }
            }
            $call = sprintf('$r->callTag(%s, $c, %s)', Compiler::string($name), Compiler::array($arguments));
            $autoescape = $parser->autoescape;

            return new TagNode(static function (Compiler $compiler) use ($call, $target, $autoescape): void {
                $compiler->write($target === null
                    ? sprintf('echo $r->%s(%s);', $autoescape ? 'escape' : 'toString', $call)
                    : sprintf('$c->vars[%s] = %s;', Compiler::string($target), $call));
            });
        };

        return $this;
    }

    /**
     * @return array<string, array{callable: callable, isSafe: bool, needsAutoescape: bool}>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, callable(Parser, Token): Node>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<string, array{callable: callable, takesContext: bool}>
     */
    public function simpleTags(): array
    {
        return $this->simpleTags;
    }

    private static function validName(string $name): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid tag or filter name.', $name));
        }

        return $name;
    }
}

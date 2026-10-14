<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

use BrewPHP\Templates\Nodes\BlockNode;
use BrewPHP\Templates\Nodes\Node;
use BrewPHP\Templates\Nodes\NodeList;
use BrewPHP\Templates\Nodes\TagNode;

/**
 * The built-in tags and filters. Each tag compiler reads its arguments
 * and writes PHP code; see the "Built-in tags and filters" reference.
 */
final class Builtins
{
    public static function library(): Library
    {
        $library = new Library();
        foreach (['if', 'for', 'block', 'extends', 'include', 'with', 'url', 'static', 'now', 'cycle', 'firstof', 'spaceless', 'autoescape', 'filter', 'comment', 'load'] as $tag) {
            $library->tag($tag, [self::class, 'tag' . ucfirst($tag)]);
        }
        $library->simpleTag('csrf_token', [self::class, 'csrfToken'], takesContext: true);
        Filters::register($library);

        return $library;
    }

    /**
     * `{% if a %}...{% elif b %}...{% else %}...{% endif %}`
     */
    public static function tagIf(Parser $parser, Token $token): Node
    {
        $branches = [[$parser->condition($parser->arguments($token)), $parser->parse(['elif', 'else', 'endif'], $token)]];
        $else = null;
        while (($next = $parser->nextToken())->tagName() !== 'endif') {
            if ($next->tagName() === 'elif') {
                $condition = self::at($next, static fn (): string => $parser->condition($parser->arguments($next)));
                $branches[] = [$condition, $parser->parse(['elif', 'else', 'endif'], $token)];
            } else {
                self::noArguments($next);
                $else = $parser->parse(['endif'], $token);
            }
        }

        return new TagNode(static function (Compiler $compiler) use ($branches, $else): void {
            foreach ($branches as $i => [$condition, $body]) {
                $compiler->write(($i === 0 ? 'if (' : '} elseif (') . $condition . ') {')->indent()->subcompile($body)->outdent();
            }
            if ($else !== null) {
                $compiler->write('} else {')->indent()->subcompile($else)->outdent();
            }
            $compiler->write('}');
        });
    }

    /**
     * `{% for item in items [reversed] %}...{% empty %}...{% endfor %}`,
     * also `{% for key, value in dict %}`.
     */
    public static function tagFor(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $variables = [$stream->expectName()];
        while ($stream->nextIf(TokenStream::OPERATOR, ',') !== null) {
            $variables[] = $stream->expectName();
        }
        $stream->expect(TokenStream::NAME, 'in');
        $sequence = $parser->filterExpression($stream);
        $reversed = $stream->nextIfName('reversed');
        $stream->expectEnd();

        $body = $parser->parse(['empty', 'endfor'], $token);
        $empty = null;
        if ($parser->nextToken()->tagName() === 'empty') {
            $empty = $parser->parse(['endfor'], $token);
            $parser->nextToken();
        }

        return new TagNode(static function (Compiler $compiler) use ($variables, $sequence, $reversed, $body, $empty): void {
            $rows = $compiler->variable('rows');
            $loop = $compiler->variable('loop');
            $row = $compiler->variable('row');
            $compiler->write(sprintf('%s = $r->loopRows(%s, %d, %s);', $rows, $sequence, count($variables), $reversed ? 'true' : 'false'));
            $compiler->write(sprintf('if (%s !== []) {', $rows))->indent();
            $compiler->write('$c->push();');
            $compiler->write(sprintf(
                '%s = new \BrewPHP\Templates\ForLoop(\count(%s), ($c->vars[\'forloop\'] ?? null) instanceof \BrewPHP\Templates\ForLoop ? $c->vars[\'forloop\'] : null);',
                $loop,
                $rows,
            ));
            $compiler->write(sprintf('$c->vars[\'forloop\'] = %s;', $loop));
            $compiler->write(sprintf('foreach (%s as %s) {', $rows, $row))->indent();
            $compiler->write($loop . '->advance();');
            foreach ($variables as $i => $variable) {
                $compiler->write(sprintf('$c->vars[%s] = %s[%d];', Compiler::string($variable), $row, $i));
            }
            $compiler->subcompile($body)->outdent()->write('}');
            $compiler->write('$c->pop();')->outdent();
            if ($empty !== null) {
                $compiler->write('} else {')->indent()->subcompile($empty)->outdent();
            }
            $compiler->write('}');
        });
    }

    /**
     * `{% block name %}...{% endblock [name] %}`
     */
    public static function tagBlock(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $name = $stream->expectName();
        $stream->expectEnd();
        $body = $parser->parse(['endblock'], $token);
        $end = $parser->nextToken();
        if ($end->tagArguments() !== '' && $end->tagArguments() !== $name) {
            throw TemplateSyntaxError::atLine(sprintf('Expected "endblock %s", got "endblock %s".', $name, $end->tagArguments()), $end->line);
        }

        return new BlockNode($name, $body);
    }

    /**
     * `{% extends "base.html" %}`: must be the first tag of the template.
     */
    public static function tagExtends(Parser $parser, Token $token): Node
    {
        if ($parser->parent !== null) {
            throw new TemplateSyntaxError('A template can only extend one template.');
        }
        if ($parser->hasContent || $parser->depth > 1) {
            throw new TemplateSyntaxError('{% extends %} must be the first tag of the template.');
        }
        $parser->parent = $parser->compileExpression($token->tagArguments());
        $parser->parentLine = $token->line;

        return self::nothing();
    }

    /**
     * `{% include "name.html" [with a=b ...] [only] %}`
     */
    public static function tagInclude(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $template = $parser->filterExpression($stream);
        $extra = [];
        $only = false;
        while (!$stream->isEnd()) {
            if ($stream->nextIfName('with')) {
                $extra = self::assignments($parser, $stream);
                if ($extra === []) {
                    throw new TemplateSyntaxError('"with" in {% include %} needs at least one name=value.');
                }
            } elseif ($stream->nextIfName('only')) {
                $only = true;
            } else {
                throw new TemplateSyntaxError(sprintf('Unexpected "%s" in {%% include %%}.', $stream->next()[1]));
            }
        }

        return new TagNode(static function (Compiler $compiler) use ($template, $extra, $only): void {
            $compiler->write(sprintf('echo $r->include(%s, $c, %s, %s);', $template, Compiler::array($extra), $only ? 'true' : 'false'));
        });
    }

    /**
     * `{% with total=items|length name="x" %}...{% endwith %}`, also `{% with value as name %}`.
     */
    public static function tagWith(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        if (!($stream->test(TokenStream::NAME) && $stream->test(TokenStream::OPERATOR, '=', 1))) {
            $value = $parser->filterExpression($stream);
            $stream->expect(TokenStream::NAME, 'as');
            $values = [$stream->expectName() => $value];
        } else {
            $values = self::assignments($parser, $stream);
        }
        $stream->expectEnd();
        if ($values === []) {
            throw new TemplateSyntaxError('{% with %} needs at least one name=value.');
        }
        $body = $parser->parse(['endwith'], $token);
        $parser->nextToken();

        return new TagNode(static function (Compiler $compiler) use ($values, $body): void {
            $compiler->write(sprintf('$c->push(%s);', Compiler::array($values)));
            $compiler->subcompile($body);
            $compiler->write('$c->pop();');
        });
    }

    /**
     * `{% url 'blog:detail' post.id [slug=post.slug] [as link] %}`
     */
    public static function tagUrl(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $name = $parser->filterExpression($stream);
        $arguments = [];
        $keywords = [];
        $target = null;
        while (!$stream->isEnd()) {
            if (self::isAs($stream)) {
                $stream->next();
                $target = $stream->expectName();
            } elseif ($stream->test(TokenStream::NAME) && $stream->test(TokenStream::OPERATOR, '=', 1)) {
                $keywords += self::assignments($parser, $stream);
            } else {
                $arguments[] = $parser->filterExpression($stream);
            }
        }
        $call = sprintf('$r->url(%s, %s, %s, %s)', $name, Compiler::array($arguments), Compiler::array($keywords), $target !== null ? 'true' : 'false');

        return self::output($parser, $call, $target);
    }

    /**
     * `{% static 'css/site.css' [as url] %}`: the path prefixed with STATIC_URL.
     */
    public static function tagStatic(Parser $parser, Token $token): Node
    {
        [$value, $target] = self::valueAndTarget($parser, $token);

        return self::output($parser, sprintf('$r->staticUrl(%s)', $value), $target);
    }

    /**
     * `{% now "Y-m-d" [as today] %}`: the current date and time, formatted
     * with PHP's date() format characters.
     */
    public static function tagNow(Parser $parser, Token $token): Node
    {
        [$value, $target] = self::valueAndTarget($parser, $token);

        return self::output($parser, sprintf('(new \DateTimeImmutable())->format($r->toString(%s))', $value), $target);
    }

    /**
     * `{% cycle 'odd' 'even' [as name [silent]] %}`, then `{% cycle name %}`.
     */
    public static function tagCycle(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $reference = $stream->peek();
        if ($reference !== null && $reference[0] === TokenStream::NAME && $stream->peek(1) === null && isset($parser->cycles[$reference[1]])) {
            $target = $reference[1];
            ['id' => $id, 'values' => $values] = $parser->cycles[$target];
            $silent = false;
        } else {
            $items = [];
            $target = null;
            $silent = false;
            while (!$stream->isEnd()) {
                if (self::isAs($stream, allowSilent: true)) {
                    $stream->next();
                    $target = $stream->expectName();
                    $silent = $stream->nextIfName('silent');
                    $stream->expectEnd();
                    break;
                }
                $items[] = $parser->filterExpression($stream);
            }
            if ($items === []) {
                throw new TemplateSyntaxError('{% cycle %} needs at least one value.');
            }
            $id = $parser->uniqueId();
            $values = Compiler::array($items);
            if ($target !== null) {
                $parser->cycles[$target] = ['id' => $id, 'values' => $values];
            }
        }
        $escape = $parser->autoescape;

        return new TagNode(static function (Compiler $compiler) use ($id, $values, $target, $silent, $escape): void {
            $value = $compiler->variable('cycle');
            $compiler->write(sprintf('%s = $r->cycle(%s, %s);', $value, Compiler::string($id), $values));
            if ($target !== null) {
                $compiler->write(sprintf('$c->vars[%s] = %s;', Compiler::string($target), $value));
            }
            if (!$silent) {
                $compiler->write(sprintf('echo $r->%s(%s);', $escape ? 'escape' : 'toString', $value));
            }
        });
    }

    /**
     * `{% firstof a b "default" [as name] %}`: the first true value.
     */
    public static function tagFirstof(Parser $parser, Token $token): Node
    {
        $stream = $parser->arguments($token);
        $values = [];
        $target = null;
        while (!$stream->isEnd()) {
            if (self::isAs($stream)) {
                $stream->next();
                $target = $stream->expectName();
                break;
            }
            $values[] = $parser->filterExpression($stream, true);
        }
        if ($values === []) {
            throw new TemplateSyntaxError('{% firstof %} needs at least one value.');
        }

        return self::output($parser, sprintf('$r->firstOf(%s)', Compiler::array($values)), $target);
    }

    /**
     * `{% spaceless %}...{% endspaceless %}`: removes whitespace between HTML tags.
     */
    public static function tagSpaceless(Parser $parser, Token $token): Node
    {
        self::noArguments($token);
        $body = $parser->parse(['endspaceless'], $token);
        $parser->nextToken();

        return new TagNode(static function (Compiler $compiler) use ($body): void {
            $compiler->write('ob_start();')->subcompile($body)->write('echo $r->spaceless((string) ob_get_clean());');
        });
    }

    /**
     * `{% autoescape off %}...{% endautoescape %}`. It applies to the
     * template code between the tags (not to included templates).
     */
    public static function tagAutoescape(Parser $parser, Token $token): Node
    {
        $mode = $token->tagArguments();
        if (!in_array($mode, ['on', 'off'], true)) {
            throw new TemplateSyntaxError('{% autoescape %} takes "on" or "off".');
        }
        $previous = $parser->autoescape;
        $parser->autoescape = $mode === 'on';
        try {
            $body = $parser->parse(['endautoescape'], $token);
        } finally {
            $parser->autoescape = $previous;
        }
        $parser->nextToken();

        return $body;
    }

    /**
     * `{% filter lower|truncatewords:5 %}...{% endfilter %}`: filters the output of the block.
     */
    public static function tagFilter(Parser $parser, Token $token): Node
    {
        $placeholder = 'brewphp_filter_content';
        $expression = $parser->compileExpression($placeholder . '|' . $token->tagArguments());
        $body = $parser->parse(['endfilter'], $token);
        $parser->nextToken();
        $escape = $parser->autoescape;

        return new TagNode(static function (Compiler $compiler) use ($placeholder, $expression, $body, $escape): void {
            $value = $compiler->variable('content');
            $compiler->write('ob_start();')->subcompile($body);
            $compiler->write(sprintf('%s = new \BrewPHP\Templates\SafeString((string) ob_get_clean());', $value));
            $code = str_replace(sprintf('$r->lookup($c, %s)', Compiler::string($placeholder)), $value, $expression);
            $compiler->write(sprintf('echo $r->%s(%s);', $escape ? 'escape' : 'toString', $code));
        });
    }

    /**
     * `{% comment %}...{% endcomment %}`: everything inside is ignored.
     */
    public static function tagComment(Parser $parser, Token $token): Node
    {
        $parser->skipPast('endcomment', $token);

        return self::nothing();
    }

    /**
     * `{% load ... %}` does nothing: libraries are always available. It is
     * accepted so templates written for Django read naturally.
     */
    public static function tagLoad(Parser $parser, Token $token): Node
    {
        return self::nothing();
    }

    /**
     * `{% csrf_token %}`: a hidden form field with the `csrf_token`
     * context variable, or nothing when there is none.
     */
    public static function csrfToken(Context $context): string|SafeString
    {
        $token = $context->get('csrf_token');
        if (!is_string($token) && !$token instanceof \Stringable || in_array((string) $token, ['', 'NOTPROVIDED'], true)) {
            return '';
        }

        return new SafeString(sprintf('<input type="hidden" name="csrfmiddlewaretoken" value="%s">', Runtime::escapeHtml((string) $token)));
    }

    // Helpers -------------------------------------------------------------------

    /**
     * `name=value` pairs.
     *
     * @return array<string, string> compiled expressions by name
     */
    private static function assignments(Parser $parser, TokenStream $stream): array
    {
        $values = [];
        while ($stream->test(TokenStream::NAME) && $stream->test(TokenStream::OPERATOR, '=', 1)) {
            $name = $stream->expectName();
            $stream->next();
            $values[$name] = $parser->filterExpression($stream);
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: string|null} compiled value and optional `as` target
     */
    private static function valueAndTarget(Parser $parser, Token $token): array
    {
        $stream = $parser->arguments($token);
        $value = $parser->filterExpression($stream);
        $target = null;
        if ($stream->nextIfName('as')) {
            $target = $stream->expectName();
        }
        $stream->expectEnd();

        return [$value, $target];
    }

    private static function isAs(TokenStream $stream, bool $allowSilent = false): bool
    {
        if (!$stream->test(TokenStream::NAME, 'as') || !$stream->test(TokenStream::NAME, null, 1)) {
            return false;
        }

        return $stream->peek(2) === null || ($allowSilent && $stream->test(TokenStream::NAME, 'silent', 2) && $stream->peek(3) === null);
    }

    /**
     * Print a value (escaped when auto-escaping is on) or store it in a variable.
     */
    private static function output(Parser $parser, string $code, ?string $target): Node
    {
        $escape = $parser->autoescape;

        return new TagNode(static function (Compiler $compiler) use ($code, $target, $escape): void {
            $compiler->write($target !== null
                ? sprintf('$c->vars[%s] = %s;', Compiler::string($target), $code)
                : sprintf('echo $r->%s(%s);', $escape ? 'escape' : 'toString', $code));
        });
    }

    private static function nothing(): Node
    {
        return new NodeList();
    }

    private static function noArguments(Token $token): void
    {
        if ($token->tagArguments() !== '') {
            throw TemplateSyntaxError::atLine(sprintf('{%% %s %%} takes no arguments.', $token->tagName()), $token->line);
        }
    }

    /**
     * Run $parse, reporting syntax errors at the line of $token.
     *
     * @template T
     * @param \Closure(): T $parse
     * @return T
     */
    private static function at(Token $token, \Closure $parse): mixed
    {
        try {
            return $parse();
        } catch (TemplateSyntaxError $e) {
            $e->templateLine ??= $token->line;
            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\Compiler;
use BrewPHP\Templates\Lexer;
use BrewPHP\Templates\TemplateSyntaxError;
use BrewPHP\Templates\Token;
use BrewPHP\Templates\TokenStream;
use PHPUnit\Framework\Attributes\DataProvider;

final class ParserTest extends TemplateTestCase
{
    public function testLexerSplitsTokensWithLines(): void
    {
        $tokens = Lexer::tokenize("Hi {{ name }}\n{% if x %}\n{# note #}{% endif %}");

        self::assertSame(
            [
                [Token::TEXT, 'Hi ', 1],
                [Token::VARIABLE, 'name', 1],
                [Token::TEXT, "\n", 1],
                [Token::BLOCK, 'if x', 2],
                [Token::TEXT, "\n", 2],
                [Token::COMMENT, 'note', 3],
                [Token::BLOCK, 'endif', 3],
            ],
            array_map(static fn (Token $t): array => [$t->type, $t->contents, $t->line], $tokens),
        );
        self::assertSame('if', $tokens[3]->tagName());
        self::assertSame('x', $tokens[3]->tagArguments());
    }

    public function testUnclosedDelimitersAreText(): void
    {
        $tokens = Lexer::tokenize('a {{ b and {% c');

        self::assertCount(1, $tokens);
        self::assertSame('a {{ b and {% c', $tokens[0]->contents);
    }

    public function testVerbatimIsOneTextToken(): void
    {
        $tokens = Lexer::tokenize("{% verbatim %}{{ raw }}\n{% if %}{% endverbatim %}{{ x }}");

        self::assertSame(Token::TEXT, $tokens[0]->type);
        self::assertSame("{{ raw }}\n{% if %}", $tokens[0]->contents);
        self::assertSame(2, $tokens[1]->line);
        self::assertSame('{{ x }}', $this->render('{% verbatim v %}{{ x }}{% endverbatim v %}'));
        self::assertSame('{% endverbatim %}', $this->render('{% verbatim v %}{% endverbatim %}{% endverbatim v %}'));
    }

    public function testTokenStream(): void
    {
        $stream = new TokenStream('post.title|default:"a \"b\""  ==  -1.5 not in (x)');
        $types = [];
        while (!$stream->isEnd()) {
            $types[] = $stream->next();
        }

        self::assertSame([
            ['name', 'post.title'],
            ['operator', '|'],
            ['name', 'default'],
            ['operator', ':'],
            ['string', '"a \"b\""'],
            ['operator', '=='],
            ['number', '-1.5'],
            ['name', 'not'],
            ['name', 'in'],
            ['operator', '('],
            ['name', 'x'],
            ['operator', ')'],
        ], $types);
    }

    public function testStringLiteralsAndNumbers(): void
    {
        self::assertSame('it&#039;s &quot;quoted&quot; \\', $this->render('{{ \'it\\\'s "quoted" \\\\\' }}'));
        self::assertSame('42 -3 1.5', $this->render('{{ 42 }} {{ -3 }} {{ 1.5 }}'));
        self::assertSame('True False ', $this->render('{{ True }} {{ false }} {{ None }}'));
    }

    public function testCompilerLiteralsStayOnOneLine(): void
    {
        self::assertSame("'a\\'b\\\\'", Compiler::string("a'b\\"));
        self::assertSame('"line\x0Anext \$x \"q\" \x00"', Compiler::string("line\nnext \$x \"q\" \0"));
        self::assertSame("['a' => 1, 0 => true, 1 => null]", Compiler::literal(['a' => 1, true, null]));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'unknown tag' => ["a\n{% frobnicate %}", 'Unknown tag "frobnicate"', 2];
        yield 'unknown filter' => ["\n\n{{ x|nope }}", 'Unknown filter "nope"', 3];
        yield 'unclosed if' => ["{% if x %}\nyes", 'Unclosed tag "if": expected "elif" or "else" or "endif"', 1];
        yield 'unclosed for' => ["x\n{% for a in b %}{% endif %}", 'Unexpected tag "endif": expected "empty" or "endfor"', 2];
        yield 'stray end tag' => ['{% endif %}', 'Unknown tag "endif"', 1];
        yield 'empty variable' => ['{{ }}', 'Empty variable tag', 1];
        yield 'bad expression' => ['{{ a b }}', 'Unexpected "b"', 1];
        yield 'unterminated string' => ['{{ "abc }}', 'Could not parse the remainder', 1];
        yield 'underscore attribute' => ['{{ post._secret }}', 'may not begin with an underscore', 1];
        yield 'underscore variable' => ['{{ __class__ }}', 'may not begin with an underscore', 1];
        yield 'extends not first' => ["hi {{ x }}\n{% extends 'base.html' %}", 'must be the first tag', 2];
        yield 'extends twice' => ["{% extends 'a.html' %}\n{% extends 'b.html' %}", 'only extend one', 2];
        yield 'duplicate block' => ["{% block a %}{% endblock %}\n{% block a %}{% endblock %}", 'Block "a" appears more than once', 2];
        yield 'mismatched endblock' => ["{% block a %}\n{% endblock b %}", 'Expected "endblock a"', 2];
        yield 'bad elif' => ["{% if a %}\n{% elif == %}{% endif %}", 'Expected a value', 2];
        yield 'bad for' => ['{% for in x %}{% endfor %}', 'Expected "in"', 1];
        yield 'bad autoescape' => ['{% autoescape maybe %}{% endautoescape %}', 'takes "on" or "off"', 1];
        yield 'unclosed comment' => ["a\n{% comment %}b", 'Unclosed tag "comment"', 2];
        yield 'unclosed verbatim' => ["\n{% verbatim %}x", 'Unclosed tag "verbatim"', 2];
        yield 'with without values' => ['{% with %}{% endwith %}', 'Unexpected end', 1];
        yield 'empty cycle' => ['{% cycle %}', 'needs at least one value', 1];
        yield 'bad if operator' => ['{% if a = b %}{% endif %}', 'Unexpected "="', 1];
        yield 'else with arguments' => ['{% if a %}{% else b %}{% endif %}', 'takes no arguments', 1];
    }

    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrorsReportNameAndLine(string $source, string $message, int $line): void
    {
        try {
            $this->templates()->engine()->fromString($source, 'page.html');
            self::fail('Expected a TemplateSyntaxError');
        } catch (TemplateSyntaxError $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame('page.html', $e->templateName);
            self::assertSame($line, $e->templateLine);
            self::assertStringContainsString(sprintf('(in template "page.html", line %d)', $line), $e->getMessage());
        }
    }

    public function testSyntaxErrorInFileKnowsTheFile(): void
    {
        $this->write(['blog/post.html' => "<h1>\n{{ post.title|nope }}</h1>"]);

        try {
            $this->templates()->render('blog/post.html');
            self::fail('Expected a TemplateSyntaxError');
        } catch (TemplateSyntaxError $e) {
            self::assertSame('blog/post.html', $e->templateName);
            self::assertSame(2, $e->templateLine);
            self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'blog/post.html', $e->templateFile);
            self::assertSame('Unknown filter "nope".', $e->rawMessage());
        }
    }
}

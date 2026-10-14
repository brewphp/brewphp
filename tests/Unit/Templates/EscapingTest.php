<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\Library;
use BrewPHP\Templates\SafeString;

/**
 * Auto-escaping, and template source must never run as PHP.
 */
final class EscapingTest extends TemplateTestCase
{
    public function testVariablesAreEscapedByDefault(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;',
            $this->render('{{ a }} {{ b }} {{ c }}', ['a' => '<script>alert("x")</script>', 'b' => '&', 'c' => "'"]),
        );
    }

    public function testSafeStringAndSafeFilter(): void
    {
        self::assertSame('<b>ok</b> <b>ok</b>', $this->render('{{ a }} {{ b|safe }}', ['a' => new SafeString('<b>ok</b>'), 'b' => '<b>ok</b>']));
        self::assertSame('&lt;b&gt;', e('<b>'));
        self::assertSame('<b>', e(mark_safe('<b>')));
    }

    public function testPhpTagsInTemplateTextArePrintedNotRun(): void
    {
        $source = "<?php echo 'INJECTED'; ?>\n<?= 'SHORT' ?>\n<? echo 1 ?>";

        self::assertSame($source, $this->render($source));
    }

    public function testPhpInStringLiteralsIsEscapedNotRun(): void
    {
        self::assertSame('&lt;?php echo &#039;x&#039;; ?&gt;', $this->render("{{ \"<?php echo 'x'; ?>\" }}"));
        self::assertSame('<?php system(1) ?>', $this->render('{% autoescape off %}{{ "<?php system(1) ?>" }}{% endautoescape %}'));
        self::assertSame('a?>b', $this->render('{{ "a?>b"|safe }}'));
        self::assertSame('x', $this->render('{{ v|default:"?>\'; echo 1; //" }}', ['v' => 'x']));
        self::assertSame('?&gt;&#039;; echo 1; //', $this->render('{{ v|default:"?>\'; echo 1; //" }}'));
    }

    public function testStringLiteralsCannotBreakOutOfThePhpString(): void
    {
        $nasty = ['\'); echo "pwned"; //', '" . system("x") . "', '${phpinfo()}', '{$x}', "\\' . 1 . '", "line\nbreak\r\0"];
        foreach ($nasty as $text) {
            $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
            self::assertSame($text, $this->render('{% autoescape off %}{{ ' . $quoted . ' }}{% endautoescape %}'), 'literal ' . $text);
            self::assertSame($text, $this->render('{% autoescape off %}' . $text . '{% endautoescape %}'), 'text ' . $text);
        }
    }

    public function testLineMarkersInTextAreHarmless(): void
    {
        self::assertSame("a\n// line 99\n?>b", $this->render("a\n// line 99\n?>b"));
    }

    public function testDangerousVariableAndFilterNamesAreRejected(): void
    {
        foreach (['{{ x;phpinfo() }}', '{{ x|up\'per }}', '{{ $x }}', '{{ x() }}', '{% block a;b %}{% endblock %}', '{% with a-b=1 %}{% endwith %}'] as $source) {
            try {
                $this->render($source);
                self::fail('Expected a syntax error for ' . $source);
            } catch (\BrewPHP\Templates\TemplateSyntaxError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFiltersReturningHtmlEscapeTheirInput(): void
    {
        $context = ['s' => "<b>\nx"];

        self::assertSame('<p>&lt;b&gt;<br>x</p>', $this->render('{{ s|linebreaks }}', $context));
        self::assertSame('&lt;b&gt;<br>x', $this->render('{{ s|linebreaksbr }}', $context));
        self::assertSame('<b><br>x', $this->render('{{ s|safe|linebreaksbr }}', $context));
    }

    public function testSimpleTagOutputIsEscapedUnlessSafe(): void
    {
        $templates = $this->templates();
        $templates->library((new Library())
            ->simpleTag('raw', static fn (): string => '<b>')
            ->simpleTag('html', static fn (): SafeString => new SafeString('<b>')));

        self::assertSame('&lt;b&gt; <b>', $templates->renderString('{% raw %} {% html %}'));
        self::assertSame('<b>', $templates->renderString('{% autoescape off %}{% raw %}{% endautoescape %}'));
    }

    public function testUrlFilterOutputIsEscaped(): void
    {
        self::assertSame('a%22b%3C', $this->render('{{ s|urlencode }}', ['s' => 'a"b<']));
        self::assertSame('&lt;b&gt;', $this->render('{{ s|cut:"x" }}', ['s' => new SafeString('<xb>')]), 'cut is not safe: its output is escaped');
    }
}

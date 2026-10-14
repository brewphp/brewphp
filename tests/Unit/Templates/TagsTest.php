<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Http\Http404;
use BrewPHP\Routing\NoReverseMatch;
use BrewPHP\Templates\Context;
use BrewPHP\Templates\Library;
use BrewPHP\Templates\TemplateError;
use PHPUnit\Framework\Attributes\DataProvider;

final class TagsTest extends TemplateTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function conditions(): iterable
    {
        $yes = '{% if CONDITION %}yes{% else %}no{% endif %}';
        $cases = [
            ['x', ['x' => 1], 'yes'],
            ['x', ['x' => 0], 'no'],
            ['x', ['x' => '0'], 'yes'],
            ['x', ['x' => []], 'no'],
            ['x', ['x' => new \ArrayObject([])], 'no'],
            ['missing', [], 'no'],
            ['not x', ['x' => ''], 'yes'],
            ['a and b', ['a' => 1, 'b' => 0], 'no'],
            ['a or b', ['a' => 0, 'b' => 1], 'yes'],
            ['not a or b', ['a' => 1, 'b' => 0], 'no'],
            ['a or b and c', ['a' => 1, 'b' => 0, 'c' => 0], 'yes'],
            ['(a or b) and c', ['a' => 1, 'b' => 0, 'c' => 0], 'no'],
            ['not (a and b)', ['a' => 1, 'b' => 0], 'yes'],
            ['x == 1', ['x' => 1.0], 'yes'],
            ['x == "1"', ['x' => 1], 'no'],
            ['x != "a"', ['x' => 'b'], 'yes'],
            ['x < 10', ['x' => 3], 'yes'],
            ['x > 10', ['x' => 3], 'no'],
            ['x <= 3', ['x' => 3], 'yes'],
            ['x >= 4', ['x' => 3], 'no'],
            ['x < 10', ['x' => 'abc'], 'no'],
            ['"b" in x', ['x' => ['a', 'b']], 'yes'],
            ['"ell" in x', ['x' => 'hello'], 'yes'],
            ['"k" in x', ['x' => ['k' => 0]], 'yes'],
            ['"z" not in x', ['x' => ['a']], 'yes'],
            ['2 in x', ['x' => new \ArrayIterator([1, 2])], 'yes'],
            ['x is None', ['x' => null], 'yes'],
            ['x is not None', ['x' => 0], 'yes'],
            ['x is True', ['x' => 1], 'no'],
            ['items|length > 1', ['items' => [1, 2]], 'yes'],
            ['not_in_list', ['not_in_list' => true], 'yes'],
        ];
        foreach ($cases as [$condition, $context, $expected]) {
            yield $condition . ' ' . json_encode($context) => [str_replace('CONDITION', $condition, $yes), $context, $expected];
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('conditions')]
    public function testIfConditions(string $source, array $context, string $expected): void
    {
        self::assertSame($expected, $this->render($source, $context));
    }

    public function testIfElif(): void
    {
        $source = '{% if n == 1 %}one{% elif n == 2 %}two{% elif n == 3 %}three{% else %}many{% endif %}';

        self::assertSame('one', $this->render($source, ['n' => 1]));
        self::assertSame('three', $this->render($source, ['n' => 3]));
        self::assertSame('many', $this->render($source, ['n' => 9]));
        self::assertSame('', $this->render('{% if n %}x{% endif %}', ['n' => 0]));
    }

    public function testForLoop(): void
    {
        $source = '{% for x in items %}{{ forloop.counter }}{{ forloop.counter0 }}{{ forloop.revcounter }}{{ forloop.revcounter0 }}'
            . '{% if forloop.first %}F{% endif %}{% if forloop.last %}L{% endif %}:{{ x }} {% endfor %}';

        self::assertSame('1021F:a 2110L:b ', $this->render($source, ['items' => ['a', 'b']]));
        self::assertSame('c b a ', $this->render('{% for x in items reversed %}{{ x }} {% endfor %}', ['items' => ['a', 'b', 'c']]));
        self::assertSame('a,b,', $this->render('{% for x in items %}{{ x }},{% endfor %}', ['items' => new \ArrayIterator(['a', 'b'])]));
        self::assertSame('h-é-', $this->render('{% for c in word %}{{ c }}-{% endfor %}', ['word' => 'hé']));
    }

    public function testForEmpty(): void
    {
        $source = '{% for x in items %}{{ x }}{% empty %}nothing{% endfor %}';

        self::assertSame('nothing', $this->render($source, ['items' => []]));
        self::assertSame('nothing', $this->render($source, []));
        self::assertSame('nothing', $this->render($source, ['items' => null]));
    }

    public function testForUnpacking(): void
    {
        self::assertSame('a=1 b=2 ', $this->render('{% for k, v in d %}{{ k }}={{ v }} {% endfor %}', ['d' => ['a' => 1, 'b' => 2]]));
        self::assertSame('a=1 b=2 ', $this->render('{% for k, v in d.items %}{{ k }}={{ v }} {% endfor %}', ['d' => ['a' => 1, 'b' => 2]]));
        self::assertSame('1-2 3-4 ', $this->render('{% for a, b in pairs %}{{ a }}-{{ b }} {% endfor %}', ['pairs' => [[1, 2], [3, 4]]]));

        $this->expectException(TemplateError::class);
        $this->expectExceptionMessage('Need 2 values to unpack');
        $this->render('{% for a, b in pairs %}{% endfor %}', ['pairs' => [[1, 2, 3]]]);
    }

    public function testForParentLoopAndScope(): void
    {
        $source = '{% for row in rows %}{% for cell in row %}{{ forloop.parentloop.counter }}.{{ forloop.counter }} {% endfor %}{% endfor %}[{{ cell }}{{ forloop }}]';

        self::assertSame('1.1 1.2 2.1 []', $this->render($source, ['rows' => [[1, 2], [3]]]));
    }

    public function testForOverNonIterableFails(): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessage('Cannot loop over a value of type int');
        $this->render('{% for x in n %}{% endfor %}', ['n' => 5]);
    }

    public function testWith(): void
    {
        self::assertSame('3 x|', $this->render('{% with total=items|length label="x" %}{{ total }} {{ label }}{% endwith %}|{{ total }}', ['items' => [1, 2, 3]]));
        self::assertSame('Ann', $this->render('{% with user.name as name %}{{ name }}{% endwith %}', ['user' => ['name' => 'Ann']]));
        self::assertSame('inner outer', $this->render('{% with x="inner" %}{{ x }}{% endwith %} {{ x }}', ['x' => 'outer']));
    }

    public function testUrl(): void
    {
        $this->makeApp();

        self::assertSame('/articles/5/', $this->render("{% url 'article' 5 %}"));
        self::assertSame('/articles/7/', $this->render("{% url 'article' id=post.id %}", ['post' => ['id' => 7]]));
        self::assertSame('/hello/a%26b/', $this->render("{% url 'hello' name %}", ['name' => 'a&b']));
        self::assertSame('/demo/posts/my-post/', $this->render("{% url 'demo:post' 'my-post' %}"));
        self::assertSame('[/articles/2/]', $this->render("{% url 'article' 2 as link %}[{{ link }}]"));
        self::assertSame('[]', $this->render("{% url 'nope' as link %}[{{ link }}]"));
        self::assertSame('/articles/3/', $this->render('{% url route 3 %}', ['route' => 'article']));
    }

    public function testUnknownUrlFails(): void
    {
        $this->makeApp();

        try {
            $this->render("x\n{% url 'nope' %}");
            self::fail('Expected an error');
        } catch (TemplateError $e) {
            self::assertInstanceOf(NoReverseMatch::class, $e->getPrevious());
            self::assertSame(2, $e->templateLine);
        }
    }

    public function testUrlRejectsMixedArguments(): void
    {
        $this->makeApp();
        $this->expectExceptionMessage('Do not mix positional and named arguments');
        $this->render("{% url 'article' 1 id=2 %}");
    }

    public function testStatic(): void
    {
        self::assertSame('/static/css/site.css', $this->render("{% static 'css/site.css' %}"));
        self::assertSame('/static/a.js?v=1&amp;x', $this->render("{% static 'a.js?v=1&x' %}"));
        self::assertSame('</static/img.png>', $this->render('{% static path as src %}<{{ src }}>', ['path' => '/img.png']));

        $templates = new \BrewPHP\Templates\Templates([$this->dir], $this->cache, staticUrl: 'https://cdn.example.com/assets');
        self::assertSame('https://cdn.example.com/assets/app.css', $templates->renderString("{% static 'app.css' %}"));
    }

    public function testNow(): void
    {
        self::assertSame(date('Y'), $this->render('{% now "Y" %}'));
        self::assertSame('[' . date('Y-m-d') . ']', $this->render('{% now "Y-m-d" as today %}[{{ today }}]'));
    }

    public function testCycle(): void
    {
        self::assertSame('odd even odd ', $this->render("{% for x in items %}{% cycle 'odd' 'even' %} {% endfor %}", ['items' => [1, 2, 3]]));
        self::assertSame(
            '<tr class="a">a</tr><tr class="b">b</tr>',
            $this->render("{% for x in items %}{% cycle 'a' 'b' as row silent %}<tr class=\"{{ row }}\">{{ row }}</tr>{% endfor %}", ['items' => [1, 2]]),
        );
        self::assertSame('x y x', $this->render("{% cycle 'x' 'y' as v %} {% cycle v %} {% cycle v %}"));
        self::assertSame('&lt;b&gt;', $this->render('{% cycle html %}', ['html' => '<b>']));
    }

    public function testFirstof(): void
    {
        self::assertSame('b', $this->render('{% firstof a b "c" %}', ['a' => '', 'b' => 'b']));
        self::assertSame('c', $this->render('{% firstof a b "c" %}'));
        self::assertSame('', $this->render('{% firstof a b %}'));
        self::assertSame('&lt;i&gt;', $this->render('{% firstof a %}', ['a' => '<i>']));
        self::assertSame('[z]', $this->render('{% firstof a "z" as v %}[{{ v }}]'));
    }

    public function testSpaceless(): void
    {
        self::assertSame('<p><a href="/">x y</a></p>', $this->render("{% spaceless %}\n<p>\n  <a href=\"/\">x y</a>\n</p>\n{% endspaceless %}"));
    }

    public function testAutoescape(): void
    {
        $context = ['html' => '<b>'];

        self::assertSame('<b> &lt;b&gt; <b>', $this->render('{% autoescape off %}{{ html }} {{ html|escape }}{% endautoescape %} {{ html|safe }}', $context));
        self::assertSame('&lt;b&gt;', $this->render('{% autoescape off %}{% autoescape on %}{{ html }}{% endautoescape %}{% endautoescape %}', $context));
    }

    public function testFilterTag(): void
    {
        self::assertSame('hello &lt;b&gt;', $this->render('{% filter lower %}HELLO {{ b }}{% endfilter %}', ['b' => '<b>']));
        self::assertSame('one two …', $this->render('{% filter lower|truncatewords:2 %}One Two Three{% endfilter %}'));
    }

    public function testCommentAndLoad(): void
    {
        self::assertSame('ab', $this->render('a{# hidden {{ x|nope }} #}{% comment "note" %}{% bogus %}{{ y }}{% endcomment %}b'));
        self::assertSame('ok', $this->render('{% load static humanize %}ok'));
    }

    public function testCsrfToken(): void
    {
        self::assertSame('<form></form>', $this->render('<form>{% csrf_token %}</form>'));
        self::assertSame(
            '<input type="hidden" name="csrfmiddlewaretoken" value="a&quot;b">',
            $this->render('{% csrf_token %}', ['csrf_token' => 'a"b']),
        );
        self::assertSame(
            '<input type="hidden" name="csrfmiddlewaretoken" value="lazy">',
            $this->render('{% csrf_token %}', ['csrf_token' => static fn (): string => 'lazy']),
        );
    }

    public function testHttpExceptionsPassThroughTemplates(): void
    {
        $templates = $this->templates();
        $templates->library((new Library())->simpleTag('fail', static fn (): never => throw new Http404('gone')));

        $this->expectException(Http404::class);
        $templates->renderString("x\n{% fail %}");
    }

    public function testOtherErrorsAreWrappedWithTheLine(): void
    {
        $templates = $this->templates();
        $templates->library((new Library())->filter('explode', static fn (): never => throw new \LogicException('boom')));

        try {
            $templates->renderString("a\nb\n{{ x|explode }}");
            self::fail('Expected an error');
        } catch (TemplateError $e) {
            self::assertSame('boom (in template "<string>", line 3)', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function testContextObject(): void
    {
        $context = new Context(['a' => 1]);
        $context->push(['a' => 2, 'b' => 3]);
        self::assertSame(2, $context->get('a'));
        $context->set('c', 4);
        $context->pop();
        self::assertSame(['a' => 1], $context->all());
        self::assertFalse($context->has('c'));
        self::assertSame('d', $context->get('missing', 'd'));
    }
}

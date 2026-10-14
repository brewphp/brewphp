<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\Filters;
use BrewPHP\Templates\SafeString;
use PHPUnit\Framework\Attributes\DataProvider;

final class FiltersTest extends TemplateTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function filters(): iterable
    {
        $date = new \DateTimeImmutable('2026-03-05 14:07:09');

        yield 'add numbers' => ['{{ n|add:2 }}', ['n' => 3], '5'];
        yield 'add numeric strings' => ['{{ n|add:"2" }}', ['n' => '3'], '5'];
        yield 'add strings' => ['{{ s|add:"!" }}', ['s' => 'hi'], 'hi!'];
        yield 'add lists' => ['{{ a|add:b|join:"," }}', ['a' => [1], 'b' => [2]], '1,2'];
        yield 'capfirst' => ['{{ s|capfirst }}', ['s' => 'élan vital'], 'Élan vital'];
        yield 'cut' => ['{{ s|cut:" " }}', ['s' => 'a b c'], 'abc'];
        yield 'date default' => ['{{ d|date }}', ['d' => $date], 'Mar 5, 2026'];
        yield 'date format' => ['{{ d|date:"Y-m-d H:i" }}', ['d' => $date], '2026-03-05 14:07'];
        yield 'date escaped characters' => ['{{ d|date:"\\a\\t H" }}', ['d' => $date], 'at 14'];
        yield 'date from string' => ['{{ d|date:"d/m/Y" }}', ['d' => '2026-01-02'], '02/01/2026'];
        yield 'date invalid' => ['[{{ d|date }}]', ['d' => 'not a date'], '[]'];
        yield 'date null' => ['[{{ d|date }}]', ['d' => null], '[]'];
        yield 'default' => ['{{ s|default:"none" }}', ['s' => ''], 'none'];
        yield 'default keeps value' => ['{{ s|default:"none" }}', ['s' => 'x'], 'x'];
        yield 'default zero' => ['{{ n|default:"none" }}', ['n' => 0], 'none'];
        yield 'default missing' => ['{{ missing|default:"none" }}', [], 'none'];
        yield 'default_if_none' => ['{{ a|default_if_none:"-" }}/{{ b|default_if_none:"-" }}', ['a' => null, 'b' => ''], '-/'];
        yield 'dictsort' => ['{% for p in people|dictsort:"age" %}{{ p.name }}{% endfor %}', ['people' => [['name' => 'b', 'age' => 30], ['name' => 'a', 'age' => 20]]], 'ab'];
        yield 'dictsort nested' => ['{% for p in people|dictsort:"info.n" %}{{ p.name }}{% endfor %}', ['people' => [['name' => 'b', 'info' => ['n' => 2]], ['name' => 'a', 'info' => ['n' => 1]]]], 'ab'];
        yield 'dictsortreversed' => ['{% for p in people|dictsortreversed:"name" %}{{ p.name }}{% endfor %}', ['people' => [['name' => 'a'], ['name' => 'b']]], 'ba'];
        yield 'divisibleby' => ['{% if n|divisibleby:3 %}yes{% endif %}', ['n' => 9], 'yes'];
        yield 'escape' => ['{{ s|escape }}', ['s' => '<a href="x">'], '&lt;a href=&quot;x&quot;&gt;'];
        yield 'escape is not doubled' => ['{{ s|escape|escape }}', ['s' => '&'], '&amp;'];
        yield 'first' => ['{{ items|first }}', ['items' => ['a', 'b']], 'a'];
        yield 'first of string' => ['{{ s|first }}', ['s' => 'éa'], 'é'];
        yield 'first of empty' => ['[{{ items|first }}]', ['items' => []], '[]'];
        yield 'floatformat default' => ['{{ a|floatformat }} {{ b|floatformat }} {{ c|floatformat }}', ['a' => 34.23234, 'b' => 34.0, 'c' => 34.26], '34.2 34 34.3'];
        yield 'floatformat digits' => ['{{ a|floatformat:2 }} {{ b|floatformat:"-2" }} {{ c|floatformat:"-2" }}', ['a' => 34.2, 'b' => 34.0, 'c' => 34.256], '34.20 34 34.26'];
        yield 'floatformat grouping' => ['{{ a|floatformat:"2g" }}', ['a' => 1234567.891], '1,234,567.89'];
        yield 'floatformat string' => ['{{ a|floatformat:1 }}', ['a' => '3.14159'], '3.1'];
        yield 'floatformat not a number' => ['{{ a|floatformat }}', ['a' => 'abc'], 'abc'];
        yield 'join' => ['{{ items|join:", " }}', ['items' => ['a', '<b>']], 'a, &lt;b&gt;'];
        yield 'join escapes glue' => ['{{ items|join:"<br>" }}', ['items' => ['a', 'b']], 'a&lt;br&gt;b'];
        yield 'join keeps safe items' => ['{{ items|join:" " }}', ['items' => [new SafeString('<i>x</i>'), 'y']], '<i>x</i> y'];
        yield 'last' => ['{{ items|last }}', ['items' => ['a', 'b']], 'b'];
        yield 'length' => ['{{ items|length }} {{ s|length }} {{ n|length }} {{ it|length }}', ['items' => [1, 2], 's' => 'héllo', 'n' => null, 'it' => new \ArrayIterator([1, 2, 3])], '2 5 0 3'];
        yield 'linebreaks' => ['{{ s|linebreaks }}', ['s' => "a\nb\n\n<c>"], "<p>a<br>b</p>\n\n<p>&lt;c&gt;</p>"];
        yield 'linebreaksbr' => ['{{ s|linebreaksbr }}', ['s' => "a\r\n<b>"], 'a<br>&lt;b&gt;'];
        yield 'lower' => ['{{ s|lower }}', ['s' => 'ÉTÉ'], 'été'];
        yield 'pluralize' => ['{{ a|pluralize }} {{ b|pluralize }} {{ c|pluralize:"es" }} {{ d|pluralize:"y,ies" }} {{ e|pluralize:"y,ies" }}', ['a' => 1, 'b' => 2, 'c' => 0, 'd' => 1, 'e' => [1, 2]], ' s es y ies'];
        yield 'safe' => ['{{ s|safe }}', ['s' => '<b>'], '<b>'];
        yield 'slice list' => ['{{ items|slice:":2"|join:"" }} {{ items|slice:"1:"|join:"" }} {{ items|slice:"-2:"|join:"" }} {{ items|slice:"::2"|join:"" }} {{ items|slice:"2"|join:"" }}', ['items' => ['a', 'b', 'c', 'd']], 'ab bcd cd ac ab'];
        yield 'slice string' => ['{{ s|slice:"1:3" }}', ['s' => 'héllo'], 'él'];
        yield 'slugify' => ['{{ s|slugify }}', ['s' => ' Hello, World! '], 'hello-world'];
        yield 'striptags' => ['{{ s|striptags }}', ['s' => '<p>Hi <b>there</b></p>'], 'Hi there'];
        yield 'time' => ['{{ d|time }} {{ d|time:"g:i A" }}', ['d' => $date], '14:07 2:07 PM'];
        yield 'timesince' => ['{{ a|timesince:b }}', ['a' => new \DateTimeImmutable('2026-01-01 10:00'), 'b' => new \DateTimeImmutable('2026-01-04 16:30')], '3 days, 6 hours'];
        yield 'timesince single unit' => ['{{ a|timesince:b }}', ['a' => new \DateTimeImmutable('2026-01-01 10:00'), 'b' => new \DateTimeImmutable('2026-01-01 10:01')], '1 minute'];
        yield 'timesince future' => ['{{ a|timesince:b }}', ['a' => new \DateTimeImmutable('2026-01-02'), 'b' => new \DateTimeImmutable('2026-01-01')], '0 minutes'];
        yield 'title' => ['{{ s|title }}', ['s' => "hello wORLD it's ok"], 'Hello World It&#039;s Ok'];
        yield 'truncatechars' => ['{{ s|truncatechars:5 }}|{{ s|truncatechars:50 }}', ['s' => 'Hello world'], 'Hell…|Hello world'];
        yield 'truncatewords' => ['{{ s|truncatewords:2 }}|{{ s|truncatewords:5 }}', ['s' => 'one two three'], 'one two …|one two three'];
        yield 'upper' => ['{{ s|upper }}', ['s' => 'été'], 'ÉTÉ'];
        yield 'urlencode' => ['{{ s|urlencode }}|{{ s|urlencode:"" }}', ['s' => 'a b/c&d'], 'a%20b/c%26d|a%20b%2Fc%26d'];
        yield 'wordcount' => ['{{ s|wordcount }}', ['s' => " one  two\nthree "], '3'];
        yield 'yesno' => ['{{ a|yesno }} {{ b|yesno }} {{ c|yesno }} {{ c|yesno:"on,off" }} {{ a|yesno:"oui,non" }}', ['a' => true, 'b' => 0, 'c' => null], 'yes no maybe off oui'];
        yield 'chain with variable argument' => ['{{ s|truncatechars:n|upper }}', ['s' => 'abcdef', 'n' => 3], 'AB…'];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('filters')]
    public function testFilter(string $source, array $context, string $expected): void
    {
        self::assertSame($expected, $this->render($source, $context));
    }

    public function testJsonScriptEscapesHtmlCharacters(): void
    {
        $output = $this->render('{{ data|json_script:"my-data" }}', ['data' => ['html' => '</script><script>alert(1)</script>', 'amp' => 'a&b', 'é' => 1]]);

        self::assertSame(
            strtr('<script id="my-data" type="application/json">{"html":"LT/scriptGTLTscriptGTalert(1)LT/scriptGT","amp":"aAMPb","é":1}</script>', ['LT' => chr(92) . 'u003C', 'GT' => chr(92) . 'u003E', 'AMP' => chr(92) . 'u0026']),
            $output,
        );
        self::assertStringNotContainsString('</script><', $output);
        self::assertSame('<script type="application/json">[1]</script>', $this->render('{{ data|json_script }}', ['data' => [1]]));
        self::assertSame('<script id="&quot;&gt;" type="application/json">1</script>', $this->render('{{ 1|json_script:id }}', ['id' => '">']));
    }

    public function testSafeFiltersKeepSafeStrings(): void
    {
        self::assertSame('<b>x</b>', $this->render('{{ s|safe|lower }}', ['s' => '<B>X</B>']));
        self::assertSame('&lt;B&gt;', $this->render('{{ s|safe|upper }}', ['s' => '<b>']));
        self::assertSame('&lt;b&gt;', $this->render('{{ s|safe|default:x }}', ['s' => '', 'x' => '<b>']));
    }

    public function testLinebreaksWithoutAutoescape(): void
    {
        self::assertSame('<p><b>a</b><br>b</p>', $this->render('{% autoescape off %}{{ s|linebreaks }}{% endautoescape %}', ['s' => "<b>a</b>\nb"]));
        self::assertSame('<i>a</i>-<i>b</i>', $this->render('{% autoescape off %}{{ items|join:"-" }}{% endautoescape %}', ['items' => ['<i>a</i>', '<i>b</i>']]));
    }

    public function testToDate(): void
    {
        self::assertSame('2026-03-05', Filters::toDate(1772668800)?->format('Y-m-d'));
        self::assertNull(Filters::toDate([]));
    }
}

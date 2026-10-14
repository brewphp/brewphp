<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Core\AltersData;
use BrewPHP\Templates\SafeString;
use BrewPHP\Templates\TemplateError;
use BrewPHP\Templates\VariableDoesNotExist;

enum Suit: string
{
    case Hearts = 'H';
}

enum Size
{
    case Large;
}

final class Article
{
    public string $title = 'Public title';

    public ?string $subtitle = null;

    public string $createdBy = 'ann';

    private string $secret = 'hidden';

    public int $deleted = 0;

    public function summary(): string
    {
        return 'summary of ' . $this->title;
    }

    public function hasComments(): bool
    {
        return true;
    }

    public function withArgument(string $required): string
    {
        return $required;
    }

    public static function staticMethod(): string
    {
        return 'static';
    }

    public function secret(): string
    {
        return $this->secret;
    }

    #[AltersData]
    public function destroy(): string
    {
        $this->deleted++;

        return 'destroyed';
    }
}

final class SpecialArticle
{
    public int $deleted = 0;

    public function destroy(): string
    {
        $this->deleted++;

        return 'destroyed';
    }
}

final class Magic
{
    /** @var array<string, mixed> */
    private array $data = ['color' => 'red', 'nothing' => null];

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

final class VariablesTest extends TemplateTestCase
{
    public function testArraysAndIndexes(): void
    {
        $context = ['user' => ['name' => 'Ann', 'tags' => ['a', 'b'], 'address' => ['city' => 'Paris']], 'list' => [10, 20]];

        self::assertSame('Ann b Paris 20', $this->render('{{ user.name }} {{ user.tags.1 }} {{ user.address.city }} {{ list.1 }}', $context));
        self::assertSame('[]', $this->render('[{{ user.missing.deeper }}{{ list.9 }}{{ nope }}]', $context));
        self::assertSame('1', $this->render('{{ obj.0 }}', ['obj' => new \ArrayObject([1, 2])]));
        self::assertSame('y', $this->render('{{ it.1 }}', ['it' => new \ArrayIterator(['x', 'y'])]));
    }

    public function testObjects(): void
    {
        $article = new Article();
        $context = ['a' => $article, 'm' => new Magic()];

        self::assertSame('Public title', $this->render('{{ a.title }}', $context));
        self::assertSame('summary of Public title', $this->render('{{ a.summary }}', $context));
        self::assertSame('True', $this->render('{{ a.has_comments }}', $context), 'snake_case finds the camelCase method');
        self::assertSame('ann', $this->render('{{ a.created_by }}', $context), 'snake_case finds the camelCase property');
        self::assertSame('[]', $this->render('[{{ a.subtitle }}]', $context));
        self::assertSame('red', $this->render('{{ m.color }}', $context));
        self::assertSame('[]', $this->render('[{{ m.nothing }}{{ m.other }}]', $context));
        self::assertSame('hidden', $this->render('{{ a.secret }}', $context), 'a public method of that name is fine');
        self::assertSame('[]', $this->render('[{{ a.with_argument }}{{ a.static_method }}]', $context), 'methods with arguments or static ones are not called');
    }

    public function testMethodsThatAlterDataAreNeverCalled(): void
    {
        $article = new Article();

        self::assertSame('[]', $this->render('[{{ a.destroy }}]', ['a' => $article]));
        self::assertSame(0, $article->deleted);
        // A method without the attribute is called.
        $special = new SpecialArticle();
        self::assertSame('destroyed', $this->render('{{ a.destroy }}', ['a' => $special]));
        self::assertSame(1, $special->deleted);
    }

    public function testValuesToText(): void
    {
        $context = [
            'yes' => true,
            'no' => false,
            'null' => null,
            'float' => 1.5,
            'suit' => Suit::Hearts,
            'size' => Size::Large,
            'date' => new \DateTimeImmutable('2026-01-02 03:04:05'),
            'safe' => new SafeString('<b>'),
        ];

        self::assertSame('True False  1.5 H Large 2026-01-02 03:04:05 <b>', $this->render('{{ yes }} {{ no }} {{ null }} {{ float }} {{ suit }} {{ size }} {{ date }} {{ safe }}', $context));
    }

    public function testArraysCannotBePrinted(): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessage('Cannot print a value of type array');
        $this->render('{{ list }}', ['list' => [1]]);
    }

    public function testClosuresAreLazy(): void
    {
        $calls = 0;
        $lazy = static function () use (&$calls): array {
            $calls++;

            return ['name' => 'lazy'];
        };

        self::assertSame('', $this->render('{% if false %}{{ user.name }}{% endif %}', ['user' => $lazy]));
        self::assertSame(0, $calls);
        self::assertSame('lazy', $this->render('{{ user.name }}', ['user' => $lazy]));
        self::assertSame(1, $calls);
        self::assertSame('x', $this->render('{{ obj.fn }}', ['obj' => ['fn' => static fn (): string => 'x']]));
    }

    public function testStrictModeRaisesForMissingVariables(): void
    {
        try {
            $this->render("ok\n{{ user.nmae }}", ['user' => ['name' => 'Ann']], strict: true);
            self::fail('Expected VariableDoesNotExist');
        } catch (VariableDoesNotExist $e) {
            self::assertSame('Variable "user.nmae" does not exist.', $e->rawMessage());
            self::assertSame(2, $e->templateLine);
        }
    }

    public function testStrictModeAllowsTestsAndDefaults(): void
    {
        $source = '{% if missing %}a{% elif user.x %}b{% else %}c{% endif %}{{ missing|default:"d" }}{{ missing|default_if_none:"e" }}{% firstof missing "f" %}';

        self::assertSame('cdef', $this->render($source, ['user' => []], strict: true));
    }
}

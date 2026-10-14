<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Support;

use BrewPHP\Support\Arr;
use BrewPHP\Support\Str;
use BrewPHP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SupportTest extends TestCase
{
    /**
     * @return iterable<array{string, string, string, string}>
     */
    public static function caseProvider(): iterable
    {
        yield ['blog_posts', 'BlogPosts', 'blog_posts', 'blog-posts'];
        yield ['SendReport', 'SendReport', 'send_report', 'send-report'];
        yield ['hello world', 'HelloWorld', 'hello_world', 'hello-world'];
    }

    #[DataProvider('caseProvider')]
    public function testCaseConversions(string $input, string $studly, string $snake, string $kebab): void
    {
        self::assertSame($studly, Str::studly($input));
        self::assertSame(lcfirst($studly), Str::camel($input));
        self::assertSame($snake, Str::snake($input));
        self::assertSame($kebab, Str::kebab($input));
    }

    public function testSlugRandomLimit(): void
    {
        self::assertSame('hello-world', Str::slug('  Hello, World! '));
        self::assertSame('a_b', Str::slug('A b', '_'));

        $random = Str::random(40);
        self::assertSame(40, strlen($random));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $random);
        self::assertNotSame($random, Str::random(40));

        self::assertSame('short', Str::limit('short', 10));
        self::assertSame('Hello...', Str::limit('Hello world', 5));
    }

    public function testArrHelpers(): void
    {
        $data = ['a' => ['b' => ['c' => 1]], 'x.y' => 'literal'];

        self::assertSame(1, Arr::get($data, 'a.b.c'));
        self::assertSame('literal', Arr::get($data, 'x.y'));
        self::assertSame('d', Arr::get($data, 'a.z', 'd'));
        self::assertTrue(Arr::has($data, 'a.b'));
        self::assertFalse(Arr::has($data, 'a.q'));

        Arr::set($data, 'a.b.d', 2);
        Arr::set($data, 'new.key', 3);
        self::assertSame(2, Arr::get($data, 'a.b.d'));
        self::assertSame(3, Arr::get($data, 'new.key'));

        self::assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], ['a']));
        self::assertSame(['b' => 2], Arr::except(['a' => 1, 'b' => 2], ['a']));
        self::assertSame([], Arr::wrap(null));
        self::assertSame(['x'], Arr::wrap('x'));
        self::assertSame(['x'], Arr::wrap(['x']));
    }
}

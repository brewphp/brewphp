<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Console;

use BrewPHP\Console\ConsoleException;
use BrewPHP\Console\Input;
use BrewPHP\Console\Output;
use BrewPHP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class InputTest extends TestCase
{
    private const ARGUMENTS = [
        'name' => ['description' => '', 'required' => true, 'default' => null],
        'extra' => ['description' => '', 'required' => false, 'default' => 'x'],
    ];

    private const OPTIONS = [
        'force' => ['description' => '', 'shortcut' => 'f', 'value' => false, 'default' => false],
        'quiet' => ['description' => '', 'shortcut' => 'q', 'value' => false, 'default' => false],
        'format' => ['description' => '', 'shortcut' => 'o', 'value' => true, 'default' => 'table'],
    ];

    public function testParsesArgumentsAndOptions(): void
    {
        $input = new Input(['ada', '--format=json', '-fq']);
        $input->bind(self::ARGUMENTS, self::OPTIONS);

        self::assertSame('ada', $input->argument('name'));
        self::assertSame('x', $input->argument('extra'));
        self::assertTrue($input->option('force'));
        self::assertTrue($input->option('quiet'));
        self::assertSame('json', $input->option('format'));
    }

    public function testValueAsNextTokenAndDoubleDash(): void
    {
        $input = new Input(['--format', 'csv', '-o', 'yaml', '--', '--not-an-option']);
        $input->bind(self::ARGUMENTS, self::OPTIONS);

        self::assertSame('yaml', $input->option('format'));
        self::assertSame('--not-an-option', $input->argument('name'));
        self::assertFalse($input->option('force'));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'unknown long' => [['a', '--nope'], 'The "--nope" option does not exist.'];
        yield 'unknown short' => [['a', '-z'], 'The "-z" option does not exist.'];
        yield 'missing value' => [['a', '--format'], 'The "--format" option needs a value.'];
        yield 'missing short value' => [['a', '-o'], 'The "-o" option needs a value.'];
        yield 'flag with value' => [['a', '--force=yes'], 'The "--force" option does not accept a value.'];
        yield 'missing argument' => [[], 'Missing required argument "name".'];
        yield 'too many' => [['a', 'b', 'c'], 'Too many arguments, expected: name extra.'];
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInput(array $tokens, string $message): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage($message);
        (new Input($tokens))->bind(self::ARGUMENTS, self::OPTIONS);
    }

    public function testUnknownNamesThrow(): void
    {
        $input = new Input([]);
        $input->bind([], []);

        try {
            $input->argument('x');
            self::fail('Expected exception');
        } catch (ConsoleException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ConsoleException::class);
        $input->option('y');
    }

    public function testFakeInputAnswers(): void
    {
        $input = Input::fake([], ['first', 'second']);

        self::assertTrue($input->isInteractive());
        self::assertSame('first', $input->readLine());
        self::assertSame('second', $input->readSecret());
        self::assertNull($input->readLine());
        self::assertFalse(Input::fake()->isInteractive());
    }

    public function testOutputStyles(): void
    {
        $plain = Output::buffered();
        $plain->writeln($plain->style('ok', 'green'));
        self::assertSame('ok' . PHP_EOL, $plain->fetch());

        $color = Output::buffered(decorated: true);
        self::assertTrue($color->isDecorated());
        self::assertSame("\033[32;1mok\033[0m", $color->style('ok', 'green', 'bold'));
        self::assertSame(2, Output::width("\033[32mok\033[0m"));
    }
}

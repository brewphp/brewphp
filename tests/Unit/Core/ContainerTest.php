<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Core;

use BrewPHP\Core\Container;
use BrewPHP\Core\ContainerException;
use BrewPHP\Tests\TestCase;

interface Clock
{
    public function now(): string;
}

final class FixedClock implements Clock
{
    public function now(): string
    {
        return '12:00';
    }
}

final class Greeter
{
    public function __construct(public readonly Clock $clock, public readonly string $greeting = 'Hello')
    {
    }
}

final class LoopA
{
    public function __construct(public LoopB $b)
    {
    }
}

final class LoopB
{
    public function __construct(public LoopA $a)
    {
    }
}

final class NeedsScalar
{
    public function __construct(public string $value)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function testBindCreatesNewInstancesEachTime(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        self::assertInstanceOf(FixedClock::class, $container->get(Clock::class));
        self::assertNotSame($container->get(Clock::class), $container->get(Clock::class));
    }

    public function testSingletonIsShared(): void
    {
        $container = new Container();
        $container->singleton(Clock::class, fn (): Clock => new FixedClock());

        self::assertSame($container->get(Clock::class), $container->get(Clock::class));
    }

    public function testInstanceAndHas(): void
    {
        $container = new Container();
        $container->instance('answer', 42);

        self::assertTrue($container->has('answer'));
        self::assertFalse($container->has('question'));
        self::assertSame(42, $container->get('answer'));
    }

    public function testAutowiresConstructorArguments(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        $greeter = $container->get(Greeter::class);
        self::assertInstanceOf(FixedClock::class, $greeter->clock);
        self::assertSame('Hello', $greeter->greeting);

        $custom = $container->make(Greeter::class, ['greeting' => 'Hi']);
        self::assertSame('Hi', $custom->greeting);
    }

    public function testCallResolvesArguments(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        $result = $container->call(fn (Clock $clock, int $times): string => str_repeat($clock->now(), $times), ['times' => '2']);

        self::assertSame('12:0012:00', $result);
    }

    public function testInterfaceWithoutBindingFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('cannot be instantiated');
        (new Container())->get(Greeter::class);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        (new Container())->get(LoopA::class);
    }

    public function testUnresolvableScalarFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$value');
        (new Container())->get(NeedsScalar::class);
    }

    public function testUnknownIdFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get('no.such.service');
    }

    public function testBindingMissingClassFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->bind(Clock::class, 'Missing\\ClockClass');
    }

    public function testResolveArgumentsCoercesUrlStrings(): void
    {
        $container = new Container();
        $function = new \ReflectionFunction(fn (int $id, float $ratio, bool $flag, ?string $missing, string $default = 'd'): null => null);

        self::assertSame(
            [5, 1.5, true, null, 'd'],
            $container->resolveArguments($function, ['id' => '5', 'ratio' => '1.5', 'flag' => 'yes']),
        );
    }
}

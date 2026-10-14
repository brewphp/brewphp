<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use Closure;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A small dependency-injection container.
 *
 * - `bind()` registers a factory that runs every time.
 * - `singleton()` registers a factory that runs once.
 * - `instance()` stores a ready-made object.
 * - `make()` builds any class, filling constructor arguments by type
 *   ("autowiring") when nothing was registered for it.
 */
class Container
{
    /** @var array<string, array{factory: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $building = [];

    /**
     * @param Closure|class-string|null $concrete
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $concrete ??= $id;
        if (!$concrete instanceof Closure && !class_exists($concrete)) {
            throw new ContainerException(sprintf('Cannot bind "%s": class "%s" does not exist.', $id, $concrete));
        }
        $factory = $concrete instanceof Closure
            ? $concrete
            : fn (Container $c): object => $c->build($concrete);

        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    /**
     * @param Closure|class-string|null $concrete
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, shared: true);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    /**
     * Resolve an entry. Unregistered class names are autowired.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $value = ($this->bindings[$id]['factory'])($this);
            if ($this->bindings[$id]['shared']) {
                $this->instances[$id] = $value;
            }

            return $value;
        }

        if (class_exists($id) || interface_exists($id)) {
            return $this->build($id);
        }

        throw new ContainerException(sprintf('Nothing is registered in the container for "%s".', $id));
    }

    /**
     * Build a new object of $class. Arguments in $parameters (by name) win
     * over autowiring.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $parameters
     * @return T
     */
    public function make(string $class, array $parameters = []): object
    {
        if ($parameters === [] && $this->has($class)) {
            /** @var T */
            return $this->get($class);
        }

        return $this->build($class, $parameters);
    }

    /**
     * Call any callable, resolving its arguments from $parameters (by name)
     * or from the container (by type).
     *
     * @param array<string, mixed> $parameters
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        $function = new \ReflectionFunction(Closure::fromCallable($callable));

        return $callable(...$this->resolveArguments($function, $parameters));
    }

    /**
     * Work out the argument list for a function or method.
     *
     * @param array<string, mixed> $parameters values by parameter name
     * @param array<class-string, object> $typed values by class (e.g. the current Request)
     * @return list<mixed>
     */
    public function resolveArguments(ReflectionFunctionAbstract $function, array $parameters = [], array $typed = []): array
    {
        $arguments = [];
        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $arguments[] = $this->resolveParameter($parameter, $parameters, $typed);
        }

        return $arguments;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $parameters
     * @return T
     */
    private function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new ContainerException(sprintf('Class "%s" does not exist.', $class));
        }
        if (isset($this->building[$class])) {
            throw new ContainerException(sprintf('Circular dependency while building "%s".', $class));
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('"%s" cannot be instantiated; bind a concrete class for it.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $this->building[$class] = true;
        try {
            return $reflection->newInstanceArgs($this->resolveArguments($constructor, $parameters));
        } finally {
            unset($this->building[$class]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<class-string, object> $typed
     */
    private function resolveParameter(ReflectionParameter $parameter, array $parameters, array $typed): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $class = $type->getName();
            foreach ($typed as $object) {
                if ($object instanceof $class) {
                    return $object;
                }
            }
        }

        if (array_key_exists($name, $parameters)) {
            return self::coerce($parameters[$name], $type);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $class = $type->getName();
            $exists = class_exists($class) || interface_exists($class);
            if ($this->has($class) || ($exists && !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull())) {
                return $this->get($class);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        $owner = $parameter->getDeclaringClass()?->getName();
        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s of %s%s().',
            $name,
            $owner !== null ? $owner . '::' : '',
            $parameter->getDeclaringFunction()->getName(),
        ));
    }

    /**
     * Convert numeric strings for int/float/bool parameters, so values
     * captured from a URL work with strict types.
     */
    private static function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!is_string($value) || !$type instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => preg_match('/^-?\d+$/', $value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
    }
}

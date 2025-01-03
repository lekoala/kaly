<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use InvalidArgumentException;
use Kaly\Util\Refl;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionClass;

/**
 * Call function or create classes with dynamic arguments
 * Can optionaly resolve arguments with a container
 * See https://github.com/yiisoft/injector for inspiration
 */
class Injector
{
    protected ?ContainerInterface $container = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Invoke any callable and resolve classes using the di container
     * You can pass named arguments or positional arguments with ...$arguments
     *
     * @param array<string,string>|string|callable $callable
     * @param array<mixed> ...$arguments
     * @return mixed
     */
    public function invoke(array|string|callable $callable, ...$arguments)
    {
        // This is needed to support [$class, $method] syntax
        $closure = Closure::fromCallable($callable);
        $reflection = new ReflectionFunction($closure);
        $parameters = $reflection->getParameters();

        $resolvedParameters = Refl::resolveParameters($parameters, $arguments, $this->container);

        $result = $reflection->invoke(...$resolvedParameters);

        return $result;
    }

    /**
     * Invoke any callable and resolve classes using the di container
     * You can pass named arguments as an array
     *
     * @param callable $callable
     * @param array<string,mixed> $arguments
     * @return mixed
     */
    public function invokeArray(callable $callable, array $arguments)
    {
        return $this->invoke($callable, ...$arguments);
    }

    /**
     * Build a an object based on its class
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<mixed> ...$arguments
     * @return T
     */
    public function make(string $class, ...$arguments)
    {
        $reflection = new ReflectionClass($class);

        // If we try to instiante an interface, use the container if available
        if ($reflection->isInterface()) {
            if ($this->container) {
                // Get a fresh object
                $clone = clone $this->container;
                return $clone->get($class);
            }
            throw new InvalidArgumentException("Cannot instantiate an interface without a container");
        }

        $constructor = $reflection->getConstructor();

        // Collect constructor's arguments. There might be no constructor
        $parameters = $constructor ? $constructor->getParameters() : [];

        $resolvedParameters = Refl::resolveParameters($parameters, $arguments, $this->container);

        $instance = $reflection->newInstanceArgs($resolvedParameters);

        return $instance;
    }

    /**
     * Build a an object based on its class
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed> $arguments
     * @return T
     */
    public function makeArray(string $class, $arguments)
    {
        return $this->make($class, ...$arguments);
    }
}

<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use Exception;
use ReflectionNamedType;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Kaly\Core\Ex;
use Throwable;

/**
 * Create class from definitions
 * See https://github.com/yiisoft/injector for inspiration
 */
class Injector
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : object)
     * @throws NotFoundExceptionInterface No entry was found for **this** identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id)
    {
        if ($id === self::class) {
            return $this;
        }
        $obj = $this->container->get($id);
        assert(is_object($obj));
        return $obj;
    }

    /**
     * Invoke any callable and resolve classes using the di container
     * You can pass named arguments or an array using ...$arguments
     *
     * @param callable $callable
     * @param array<mixed> ...$arguments
     * @return mixed
     */
    public function invoke(callable $callable, ...$arguments)
    {
        $callable = Closure::fromCallable($callable);
        $reflection = new ReflectionFunction($callable);
        $parameters = $reflection->getParameters();

        $isPositional = false;
        foreach ($arguments as $idx => $arg) {
            if (is_int($idx)) {
                $isPositional = true;
            }
        }

        $args = [];
        foreach ($parameters as $parameter) {
            // Last argument is variadic
            if ($parameter->isVariadic()) {
                $args = array_merge($args, $arguments);
                break;
            }

            // Check if argument is already provided by name or position
            $name = $parameter->getName();
            if (isset($arguments[$name])) {
                $args[$name] = $arguments[$name];
                continue;
            }
            // If we provided positional arguments instead of named ones
            if ($isPositional && isset($arguments[0])) {
                $args[count($args)] = array_shift($arguments);
                continue;
            }

            // Or resolve using it's type
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                if (!$type->isBuiltin()) {
                    $args[$name] = $this->get($typeName);
                    continue;
                }
            }

            // Use default value provided by code
            if ($parameter->isDefaultValueAvailable() && $parameter->isOptional()) {
                $args[$name] = $parameter->getDefaultValue();
                continue;
            }

            // We can pass null
            if ($parameter->allowsNull()) {
                $args[$name] = null;
                continue;
            }
        }

        $result = $callable(...$args);

        return $result;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param string|null $id
     * @param bool $cache
     * @return T
     */
    public function make(string $class, ?string $id = null, bool $cache = true)
    {
        // When cloning, our container gets a clean slate
        $container = $cache ? $this->container : clone $this->container;
        $inst = $id ? $container->get($id) : $container->get($class);
        assert($inst instanceof $class);
        return $inst;
    }
}

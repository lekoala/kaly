<?php

declare(strict_types=1);

namespace Kaly;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionNamedType;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionFunction;

/**
 * A dead simple container that implements strictly the container interface
 * You can only initialize definitions with the constructor, after that
 * the container is "locked"
 * Any get call always provide the same result
 *
 * Credits to for inspiration
 * @link https://github.com/devanych/di-container
 */
class Di implements ContainerInterface
{
    /**
     * Define custom definitions for service not matching a class name
     * @var array<string, mixed>
     */
    protected array $definitions;

    /**
     * Store all requested instances by id
     * @var array<string, object|null>
     */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $definitions
     */
    public function __construct(array $definitions = [])
    {
        $this->definitions = $definitions;
    }

    /**
     * @return string[]
     */
    public function listDefinitions(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @phpstan-ignore-next-line
     * @throws NotFoundExceptionInterface
     */
    protected function throwNotFound(string $message): void
    {
        $class = new class extends InvalidArgumentException implements NotFoundExceptionInterface
        {
        };
        throw new $class($message);
    }

    /**
     * @phpstan-ignore-next-line
     * @throws ContainerExceptionInterface
     */
    protected function throwError(string $message): void
    {
        $class = new class extends Exception implements ContainerExceptionInterface
        {
        };
        throw new $class($message);
    }

    protected function build(string $id): ?object
    {
        $providedArguments = [];
        $namedArguments = false;

        // If we have a definition
        if (isset($this->definitions[$id])) {
            $definition = $this->definitions[$id];

            // Can be defined as a closure
            if ($definition instanceof Closure) {
                return $definition($this);
            }

            // Can be an instance of something
            // eg: 'app' => $this
            if (is_object($definition)) {
                return $definition;
            }
            if (is_string($definition) && class_exists($definition)) {
                // Can be an alias or interface binding
                // eg: somealias => MyClass::class or SomeInterface::class => MyClass::class
                $id = $definition;
            } elseif (is_array($definition)) {
                // Can be an array of argument to feed to the constructor
                // eg: MyClass::class => ["arg", "arg2"]
                $providedArguments = $definition;

                // Arguments can be an associative array, otherwise they will be passed by order
                // eg: MyClass::class => ["arg" => "val", "arg2" => "val2"]
                if ($providedArguments !== array_values($providedArguments)) {
                    $namedArguments = true;
                }
            }
        }

        if (!class_exists($id)) {
            $this->throwError("Unable to create object `$id`. Class does not exist.");
            return null;
        }

        $reflection = new ReflectionClass($id);
        $constructor = $reflection->getConstructor();

        // There is no constructor, return
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        // Collect the arguments
        $arguments = [];
        $i = -1;
        foreach ($constructor->getParameters() as $parameter) {
            $i++;

            $paramName =  $parameter->getName();

            // It is provided by definition, either named or positional
            if ($namedArguments) {
                if (isset($providedArguments[$paramName])) {
                    $arguments[] = $providedArguments[$paramName];
                    continue;
                }
            } else {
                if (isset($providedArguments[$i])) {
                    $arguments[] = $providedArguments[$i];
                    continue;
                }
            }

            // Fetch from container based on argument type
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                // It's a class
                if (!$type->isBuiltin()) {
                    // Fetch closures by parameter name if they have the proper return type
                    // This is only the case if you don't bind the requested class or interface in definitions
                    // eg: __constructor(PDO $db) will match the 'db' key in the DI container IF no PDO::class exists
                    if (!isset($this->definitions[$typeName]) && isset($this->definitions[$paramName])) {
                        $paramDefinition = $this->definitions[$paramName];
                        if ($paramDefinition instanceof Closure) {
                            $reflectionClosure = new ReflectionFunction($paramDefinition);
                            $returnType = $reflectionClosure->getReturnType();
                            if ($returnType instanceof ReflectionNamedType && $returnType->getName() === $typeName) {
                                $arguments[] = $this->get($paramName);
                                continue;
                            }
                        }
                    }

                    // Fetch custom objects based on class name
                    if ($this->has($typeName)) {
                        $arguments[] = $this->get($typeName);
                        continue;
                    }
                }

                // Built in is : string, float, bool, int, iterable, mixed, array
                if ($type->isBuiltin()) {
                    // Provide an empty array if needed
                    if ($typeName === 'array' && !$parameter->isDefaultValueAvailable()) {
                        $arguments[] = [];
                        continue;
                    }
                }
            }

            // Use default value provided by code
            if ($parameter->isDefaultValueAvailable() && $parameter->isOptional()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $this->throwError("Unable to create object `$id`. Unable to process parameter: `$paramName`.");
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @link https://github.com/php-fig/container/issues/33
     * @phpstan-ignore-next-line
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     * @phpstan-ignore-next-line
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     * @return object|null Entry.
     */
    public function get(string $id): ?object
    {
        if (!$this->has($id)) {
            $this->throwNotFound("Unable to create object `$id` because it does not exist");
        }

        // Di can return itself
        if ($id == __CLASS__) {
            return $this;
        }

        // A cached instance does not exist yet, build it
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->build($id);
        }

        // Return cached instance
        return $this->instances[$id];
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     */
    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || class_exists($id);
    }
}

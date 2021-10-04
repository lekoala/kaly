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
use ReflectionMethod;

/**
 * A dead simple container that implements strictly the container interface
 * You can only initialize definitions with the constructor, after that the container is "locked"
 *
 * Any get call always provide the same result because we serve cached instance
 * If you need new instances, get a factory from the container
 *
 * Keys matching the class:name pattern will be used to feed parameters to the constructor
 * Keys matching the class-> pattern will be used to call methods on the new instance
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
     * List services that require actual definitions, not simple class_exists calls
     * @var array<int, string>
     */
    protected array $strictDefinitions;

    /**
     * Store all requested instances by id
     * @var array<string, object|null>
     */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $definitions
     * @param array<int, string> $strictDefinitions
     */
    public function __construct(array $definitions = [], array $strictDefinitions = [])
    {
        $this->definitions = $definitions;
        $this->strictDefinitions = $strictDefinitions;
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

    /**
     * @return mixed
     */
    protected function expandDefinition(string $key)
    {
        $definition = $this->definitions[$key] ?? null;
        if ($definition && $definition instanceof Closure) {
            $definition = $definition($this);
        }
        return $definition;
    }

    /**
     * @return array<mixed>|null
     */
    protected function getConfigCalls(string $id): ?array
    {
        return $this->expandDefinition($id . '->');
    }

    /**
     * Check if a param is defined, even if it has a null value
     */
    protected function hasParameter(string $id, string $param): bool
    {
        return array_key_exists($id . ':' . $param, $this->definitions);
    }

    /**
     * @return mixed
     */
    protected function getParameter(string $id, string $param)
    {
        return $this->expandDefinition($id . ':' . $param);
    }

    protected function build(string $id): object
    {
        $providedArguments = [];
        $namedArguments = false;

        // If we have a definition
        $definition = $this->expandDefinition($id);
        if ($definition !== null) {
            // Can be an instance of something
            // eg: 'app' => $this or 'app' => function() { return $someObject; }
            if (is_object($definition)) {
                $this->configure($definition, $id);
                return $definition;
            }
            // Can be an alias or interface binding
            // eg: somealias => MyClass::class or SomeInterface::class => MyClass::class
            if (is_string($definition) && class_exists($definition)) {
                return $this->build($definition);
            }
            // Can be an array of argument to feed to the constructor
            if (is_array($definition)) {
                // Positional arguments
                // eg: MyClass::class => ["arg", "arg2"]
                $providedArguments = $definition;

                // Or associative arguments
                // eg: MyClass::class => ["arg" => "val", "arg2" => "val2"]
                if ($providedArguments !== array_values($providedArguments)) {
                    $namedArguments = true;
                }
            }
        }

        if (!class_exists($id)) {
            $this->throwError("Unable to create object `$id`. Class does not exist.");
        }

        /** @var class-string $class  */
        $class = $id;
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        // Collect the arguments
        $constructorParameters = [];
        if ($constructor) {
            $constructorParameters = $constructor->getParameters();
        }
        $arguments = [];
        $i = -1;
        foreach ($constructorParameters as $parameter) {
            $i++;

            $paramName =  $parameter->getName();

            // It is provided by definition, either named or positional
            $paramKey = $namedArguments ? $paramName : $i;
            if (isset($providedArguments[$paramKey])) {
                $arguments[] = $providedArguments[$paramKey];
                continue;
            }

            // It is provided by parametrical syntax id:arg
            if ($this->hasParameter($id, $paramName)) {
                $arguments[] = $this->getParameter($id, $paramName);
                continue;
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

        $instance = $reflection->newInstanceArgs($arguments);
        $this->configure($instance, $id);

        return $instance;
    }

    /**
     * Call additionnal methods after instantiation if registered
     * under the {class}-> convention in the definitions
     */
    protected function configure(object $instance, string $id): void
    {
        $instanceClass = get_class($instance);
        $configCalls = $this->getConfigCalls($id);
        // If $id is an interface, we may also have implementation calls
        if ($instanceClass !== $id) {
            $instanceCalls = $this->getConfigCalls($instanceClass);
            if (!$configCalls) {
                $configCalls = $instanceCalls;
            } elseif ($instanceCalls) {
                $configCalls = array_merge($configCalls, $instanceCalls);
            }
        }
        if ($configCalls === null) {
            return;
        }
        if (!is_array($configCalls)) {
            $type = get_debug_type($configCalls);
            $this->throwError("Invalid calls definition for `$id`. It should be an array instead of: `$type`");
        }
        foreach ($configCalls as $callMethod => $callArguments) {
            // Calls can be closures
            if ($callArguments instanceof Closure) {
                $callArguments($instance, $this);
                continue;
            }
            // Calls can be queued
            if (is_int($callMethod)) {
                $callMethod = key($callArguments);
                $callArguments = $callArguments[$callMethod];
            }
            $callMethod = (string) $callMethod;
            if (!method_exists($instance, $callMethod)) {
                $this->throwError("Method `$callMethod` does not exist on `$id`");
            }
            /** @var callable $callable  */
            $callable = [$instance, $callMethod];
            if (!is_callable($callable)) {
                $this->throwError("Method `$callMethod` is not callable on `$id`");
            }
            if (is_array($callArguments) && !array_is_list($callArguments)) {
                // Reorganize arguments according to definition
                // TODO: could be improved with named arguments
                $reflMethod = new ReflectionMethod($instance, $callMethod);
                $reflArguments = $reflMethod->getParameters();
                $newArguments = [];
                foreach ($reflArguments as $reflArgument) {
                    $reflArgumentName = $reflArgument->getName();
                    // We don't allow invalid definition
                    if (!isset($callArguments[$reflArgumentName])) {
                        $this->throwError("Method `$callMethod` does not have a parameter `$reflArgumentName`");
                    }
                    $newArguments[] = $callArguments[$reflArgumentName];
                }
                call_user_func_array($callable, $newArguments);
            } else {
                // This allow passing an array as the first argument if necessary
                call_user_func($callable, $callArguments);
            }
        }
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @link https://github.com/php-fig/container/issues/33
     * @phpstan-ignore-next-line
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     * @phpstan-ignore-next-line
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     * @return object Entry.
     */
    public function get(string $id): object
    {
        if (!$this->has($id)) {
            $this->throwNotFound("Unable to create object `$id` because it does not exist");
        }

        // Di can return itself
        if ($id === __CLASS__) {
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
        if (isset($this->definitions[$id])) {
            return true;
        }
        return !in_array($id, $this->strictDefinitions) && class_exists($id);
    }
}

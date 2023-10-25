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
    public const FRESH = ":new";
    protected const CONFIG_TOKEN = "->";
    protected const PARAM_TOKEN = ":";

    /**
     * Define custom definitions for service not matching a class name
     * @var array<string, mixed>
     */
    protected array $definitions;

    /**
     * These ids are not cached
     * @var array<string>
     */
    protected array $noCache;

    /**
     * Store all requested instances by id
     * @var array<string, object|null>
     */
    protected array $instances = [];

    /**
     * @param array<string, mixed> $definitions
     * @param array<string> $noCache
     */
    public function __construct(array $definitions = [], array $noCache = [])
    {
        $this->definitions = $definitions;
        $this->noCache = $noCache;
    }

    /**
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
        $definition = $this->expandDefinition($id . self::CONFIG_TOKEN);
        if ($definition === null || is_array($definition)) {
            return $definition;
        }
        $this->throwError("Config calls for `$id` must be stored as array.");
        return null;
    }

    /**
     * Check if a param is defined, even if it has a null value
     */
    protected function hasParameter(string $id, string $param): bool
    {
        return array_key_exists($id . self::PARAM_TOKEN . $param, $this->definitions);
    }

    /**
     * @return mixed
     */
    protected function getParameter(string $id, string $param)
    {
        return $this->expandDefinition($id . self::PARAM_TOKEN . $param);
    }

    protected function build(string $id): object
    {
        $providedArguments = [];
        $namedArguments = false;

        // If we have a definition
        $definition = $this->expandDefinition($id);
        if ($definition !== null) {
            // Can be an instance of something or a factory function
            // eg: 'app' => $this or 'app' => function() { return $someObject; }
            if (is_object($definition)) {
                // Apply any further configuration if needed
                $this->configure($definition, $id);
                return $definition;
            }
            // Can be an alias or interface binding or a callable
            // eg: somealias => MyClass::class or SomeInterface::class => MyClass::class
            if (is_string($definition)) {
                if ($this->has($definition)) {
                    $instance = $this->get($definition);
                    // Verify that interface binding respect the contract
                    if (interface_exists($id) && !$instance instanceof $id) {
                        $this->throwError("Object `$id` is bound to an invalid class.");
                    }
                    return $instance;
                } elseif (is_callable($definition)) {
                    $parts = explode("::", $definition);
                    $class = $parts[0];
                    $method = $parts[1] ?? '__invoke';
                    $instance = $this->get($class);
                    return $instance->$method();
                } else {
                    $this->throwError("Invalid definition for `$id`.");
                }
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
            if (interface_exists($id)) {
                $this->throwError("Interface `$id` is not bound to a class.");
            } else {
                $this->throwError("Unable to create object `$id`. Class does not exist.");
            }
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

        /** @var object $instance */
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
                $instance->{$callMethod}(...$newArguments);
            } else {
                // This allow passing an array as the first argument if necessary
                $instance->{$callMethod}($callArguments);
            }
        }
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     * @return object Entry.
     */
    public function get(string $id): object
    {
        // Get fresh instance if requested or part of no cache
        $fresh = in_array($id, $this->noCache);
        if (str_ends_with($id, self::FRESH)) {
            $fresh = true;
            $id = substr($id, 0, -strlen(self::FRESH));
        }

        if (!$this->has($id)) {
            $this->throwNotFound("Unable to create object `$id` because it does not exist");
        }

        // Di can return itself
        if ($id === __CLASS__) {
            return $this;
        }

        // We requested a fresh instance
        if ($fresh) {
            return $this->build($id);
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
        // Definitions with null are not valid
        if (array_key_exists($id, $this->definitions)) {
            return isset($this->definitions[$id]);
        }
        // Any existing class can be built without definition
        return class_exists($id);
    }
}

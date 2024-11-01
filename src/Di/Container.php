<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

/**
 * A simple yet powerful container that implements strictly the container interface
 *
 * The only public methods are the ones from the interface
 *
 * You can only initialize definitions with the constructor, after that the container is "locked"
 * You may still alter definitions if you have defined them beforehand
 *
 * All returned objects are cached. If you need fresh instances, return factories from the container
 * You can also clone the container to get a fresh container without cached instances
 *
 * Credits to for inspiration
 * @link https://github.com/devanych/di-container
 */
class Container implements ContainerInterface
{
    protected Definitions $definitions;
    /**
     * @var array<string,bool>
     */
    protected array $building = [];
    /**
     * @var array<string,object>
     */
    protected array $instances = [];

    /**
     * @param Definitions|array<string,class-string|Closure> $definitions
     */
    public function __construct(Definitions|array $definitions = [])
    {
        $this->definitions = is_array($definitions) ? new Definitions($definitions) : $definitions;
        // Makes it easier to debug
        $this->definitions->sort();
    }

    /**
     * Execute closures in definitions to get concrete values
     */
    protected function loadDefinition(string $id): null|string|object
    {
        $definition = $this->definitions->get($id);
        if ($definition && $definition instanceof Closure) {
            $definition = $definition($this, $this->definitions->getParameters($id));
        }
        return $definition;
    }

    /**
     * @param string $id
     * @return object
     * @throws CircularReferenceException
     * @throws ContainerException
     */
    protected function build(string $id): object
    {
        // Avoid issues when resolving the container
        if ($id === self::class) {
            return $this;
        }

        // If we ask for an injector
        if ($id === Injector::class) {
            return new Injector($this);
        }

        // If we have a definition
        $definition = $this->loadDefinition($id);
        if ($definition !== null) {
            // Can be an instance of something
            // eg: 'app' => $app or 'app' => fn () => new App
            if (is_object($definition)) {
                // Apply any further configuration if needed
                // These will run only once since we cache instances
                $this->configure($definition, $id);
                return $definition;
            }

            // Can be an interface binding
            // eg: SomeInterface::class => MyClass::class
            // assert(is_string($definition));
            $id = $definition;
        }

        // Use try/finally pattern to make sure we unset building[$id] when throwing exceptions
        try {
            if (isset($this->building[$id])) {
                $buildChain = implode(', ', array_keys($this->building));
                throw new CircularReferenceException("Circular reference to `$id` in `{$buildChain}`");
            }

            if (!class_exists($id)) {
                throw new ContainerException("Class `$id` does not exist");
            }

            $this->building[$id] = true;

            $reflection = new ReflectionClass($id);
            $constructor = $reflection->getConstructor();

            // Collect constructor's arguments. There might be no constructor
            $constructorParameters = $constructor ? $constructor->getParameters() : [];
            $definedParameters = $this->definitions->getParameters($id);
            $arguments = [];
            foreach ($constructorParameters as $parameter) {
                // Get parameters from the definitions if set
                $name = $parameter->getName();
                if (isset($definedParameters[$name])) {
                    $arguments[] = $definedParameters[$name];
                    continue;
                }

                // Fetch from container based on argument type
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $typeName = $type->getName();

                    // Instantiate classes or interfaces
                    // A built-in type is any type that is not a class, interface, or trait.
                    if (!$type->isBuiltin()) {
                        assert(class_exists($typeName), "$typeName is not defined in container");
                        // Check if we have any custom resolver
                        $resolvers = $this->definitions->getResolvers($typeName);
                        if (!empty($resolvers)) {
                            foreach ($resolvers as $key => $value) {
                                $apply = false;
                                if ($key === '*' && $value instanceof Closure) {
                                    $apply = true;
                                } elseif (str_contains($key, '\\') && is_a($key, $id, true)) {
                                    $apply = true;
                                } elseif ($key === $name) {
                                    $apply = true;
                                }
                                if ($apply) {
                                    $name = $value instanceof Closure ? $value($name, $id) : $value;
                                }
                            }
                        }

                        // If we have a proper definition, use that first
                        if ($this->definitions->has($typeName)) {
                            $arguments[] = $this->get($typeName);
                            continue;
                        }

                        // Then fetch based on argument name (eg: for named services)
                        if ($this->definitions->has($name)) {
                            $argument = $this->get($name);

                            // Make sure type matches
                            if (!($argument instanceof $typeName)) {
                                $t = $argument::class;
                                throw new ContainerException("Cannot create `$id`, argument `$name` is of type `$t`");
                            }

                            $arguments[] = $argument;
                            continue;
                        }

                        // Finally, autoresolve for classes without a definition
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

                // We can pass null
                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }

                // If we reached this, we didn't manage to create the argument
                throw new ContainerException("Unable to create object `$id`, missing parameter: `$name`");
            }

            /** @var object $instance */
            $instance = $reflection->newInstanceArgs($arguments);
            $this->configure($instance, $id);
        } finally {
            unset($this->building[$id]);
        }

        return $instance;
    }

    /**
     * Call additionnal methods after instantiation
     * Callbacks will match based on the class name and the id
     *
     * @param object $instance The instance to configure
     * @param string $id Id in the container
     * @return void
     */
    protected function configure(object $instance, string $id): void
    {
        $interfaceExists = interface_exists($id, false);
        $instanceClass = $instance::class;
        $callbacks = $this->definitions->getCallbacks($id);
        // If $id is an interface or a named service, we may also have class calls
        // Interfaces callbacks are executed first, but named callbacks are executed last
        if ($instanceClass !== $id) {
            $instanceCallbacks = $this->definitions->getCallbacks($instanceClass);
            if ($interfaceExists) {
                $callbacks = array_merge($callbacks, $instanceCallbacks);
            } else {
                $callbacks = array_merge($instanceCallbacks, $callbacks);
            }
        }
        foreach ($callbacks as $closure) {
            $closure($instance, $this);
        }
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
    public function get(string $id): object
    {
        // If has($id) returns false, get($id) MUST throw a NotFoundExceptionInterface.
        if ($this->has($id) === false) {
            throw new ReferenceNotFoundException("`$id` is not set");
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
        if ($this->definitions->has($id)) {
            return true;
        }
        // Any existing class can be built without definition
        // It's the same has having SomeClass => null as a definition
        return class_exists($id);
    }

    public function __clone()
    {
        // Clean instances, but keep definitions
        $this->building = [];
        $this->instances = [];
    }
}

<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;

/**
 * This class is used to build definitions for the DI Container
 *
 * At its core, there is a map of id/class => class/object
 *
 * But it also allows defining parameters, callbacks after instantiation and custom
 * resolvers for specific classes or interfaces
 */
class Definitions
{
    /**
     * Store definitions as a map
     * Typically, the key is a class name or a custom id
     * Class strings are resolved to a class instance while objects are returned as is
     * If the object is a Closure, it is executed as a factory method
     * @var array<string,class-string|object|null>
     */
    protected array $values = [];

    /**
     * Defines callbacks to be called after an object is instantiated
     * @var array<string,array<string,callable>>
     */
    protected array $callbacks = [];

    /**
     * Defines parameters passed to a given id
     * @var array<string,array<string,mixed>>
     */
    protected array $parameters = [];

    /**
     * Resolve arguments based on custom conditions
     * @var array<class-string,array<string,callable|class-string>>
     */
    protected array $resolvers = [];

    /**
     * Lock status
     */
    protected bool $locked = false;

    /**
     * You can create the definitions with a basic array that map interfaces/ids to a class name or a closure
     * @param array<string,class-string|object|null>|Definitions|null $definitions
     */
    public function __construct(array|Definitions|null $definitions = null)
    {
        if ($definitions === null) {
            return;
        }
        // Passing an array is like a call to set with key, value
        if (is_array($definitions)) {
            $this->setAll($definitions);
        } else {
            $this->merge($definitions);
        }
    }

    /**
     * Pre PHP 8.4 helper for a better syntax
     * @param array<string,class-string|object>|Definitions|null $definitions
     */
    public static function create(array|Definitions|null $definitions = null): self
    {
        return new Definitions($definitions);
    }

    public function merge(Definitions $definitions): void
    {
        $this->values = array_merge($this->values, $definitions->getValues());

        //@phpstan-ignore-next-line
        $this->callbacks = $this->mergeDefinitionsData($this->callbacks, $definitions->getCallbacks());
        //@phpstan-ignore-next-line
        $this->parameters = $this->mergeDefinitionsData($this->parameters, $definitions->getParameters());
        //@phpstan-ignore-next-line
        $this->resolvers = $this->mergeDefinitionsData($this->resolvers, $definitions->getResolvers());
    }

    /**
     * @param array<string,array<mixed>> $arr
     * @param array<string,array<mixed>> $arr2
     * @return array<string,array<mixed>>
     */
    public function mergeDefinitionsData(array $arr, array $arr2): array
    {
        foreach ($arr2 as $key => $values) {
            $arr[$key] = array_merge($arr[$key] ?? [], $values);
        }
        return $arr;
    }

    /**
     * @return array<string,class-string|object|null>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return array<string,array<string,callable>>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<class-string,array<string,callable|class-string>>
     */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }

    public function sort(): void
    {
        ksort($this->values);
        ksort($this->callbacks);
        ksort($this->parameters);
        ksort($this->resolvers);
    }

    /**
     * Check if entry exists
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        // A value can be null
        // isset() does not return true for array keys that correspond to a null value, while array_key_exists() does.
        // see https://www.php.net/manual/en/function.array-key-exists.php
        return array_key_exists($id, $this->values);
    }

    /**
     * Check if entry does not exist
     * @param string $id
     * @return bool
     */
    public function miss(string $id): bool
    {
        return !$this->has($id);
    }

    /**
     * Get an entry
     * @param string $id
     * @return class-string|object|null
     */
    public function get(string $id): mixed
    {
        return $this->values[$id] ?? null;
    }

    /**
     * Similar to get, but expand any lazy closure
     * @param string $id
     * @return string|object|null
     */
    public function expand(string $id): string|object|null
    {
        $entry = $this->get($id);
        if ($entry && $entry instanceof Closure) {
            $entry = $entry($this, $this->parametersFor($id));
            assert(is_null($entry) || is_object($entry) || is_string($entry));
        }
        return $entry;
    }

    /**
     * Add an entry
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    public function set(string $id, string|object|null $value = null): self
    {
        assert(!$this->locked);
        assert(!is_string($value) || class_exists($value));
        $this->values[$id] = $value;
        return $this;
    }

    /**
     * Add an entry if not set yet
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    public function setDefault(string $id, string|object|null $value = null): self
    {
        if ($this->has($id)) {
            return $this;
        }
        return $this->set($id, $value);
    }

    /**
     * @param array<string,object|class-string|null> $definitions
     * @return void
     */
    public function setAll(array $definitions): void
    {
        foreach ($definitions as $k => $v) {
            $this->set($k, $v);
        }
    }

    /**
     * Register an instance
     * Binds to all classes and interfaces unless already set
     * @param object $obj
     * @return self
     */
    public function add(object $obj): self
    {
        assert(!$this->locked);
        $interfaces = class_implements($obj);
        foreach ($interfaces as $interface) {
            if (!$this->has($interface)) {
                $this->values[$interface] = $obj;
            }
        }
        $parents = class_parents($obj);
        foreach ($parents as $parent) {
            if (!$this->has($parent)) {
                $this->values[$parent] = $obj;
            }
        }
        $this->values[$obj::class] = $obj;
        return $this;
    }

    /**
     * Bind an interface to a given class
     *
     * @param class-string $class
     * @param class-string $interface Optional if there is only one interface
     * @param array<mixed> ...$parameters
     * @return self
     */
    public function bind(string $class, ?string $interface = null, ...$parameters): self
    {
        assert(!$this->locked);
        assert(class_exists($class), "Class `$class` does not exist");
        if ($interface === null) {
            $interfaces = class_implements($class);
            if (count($interfaces) === 1) {
                $interface = key($interfaces);
            }
        }
        assert($interface !== null && interface_exists($interface), "Interface `$interface` does not exist");
        if (!empty($parameters)) {
            $this->parameters($class, ...$parameters);
        }
        return $this->set($interface, $class);
    }

    /**
     * Define how to map constructors arguments when building objects of a given class
     *
     * @param class-string $class
     * @param string $key The parameter name or * for all parameters
     * @param callable|string $value fn(string $constructor, string $class) or a definition id
     * @return self
     */
    public function resolve(string $class, string $key, callable|string $value): self
    {
        assert(!$this->locked);
        $this->resolvers[$class][$key] = $value;
        return $this;
    }

    /**
     * @param class-string $class
     * @return array<string,callable|class-string>
     */
    public function resolversFor(string $class): array
    {
        return $this->resolvers[$class] ?? [];
    }

    /**
     * Provide a parameter for an entry
     *
     * @param string $id
     * @param string $name
     * @return self
     */
    public function parameter(string $id, string $name, mixed $value): self
    {
        assert(!$this->locked);
        $this->parameters[$id][$name] = $value;
        return $this;
    }

    /**
     * Provide a list of parameters for an entry
     * Best used with named params, eg: params(Xyz::class, param1: 'somevalue', param2: 'someotherval')
     * @param array<mixed> ...$params
     */
    public function parameters(string $id, ...$params): self
    {
        assert(!$this->locked);
        foreach ($params as $k => $v) {
            $this->parameter($id, (string)$k, $v);
        }
        return $this;
    }

    /**
     * Retrieve parameters for an entry
     * @param ?string $id
     * @return array<string,mixed>
     */
    public function parametersFor(?string $id = null): array
    {
        if ($id === null) {
            return [];
        }
        return $this->parameters[$id] ?? [];
    }

    /**
     * Retrieve parameters for an entry and its base class
     * @param class-string $class
     * @param ?string $id
     * @return array<string,mixed>
     */
    public function allParametersFor(string $class, ?string $id = null): array
    {
        return array_merge($this->parametersFor($class), $this->parametersFor($id));
    }

    /**
     * Provide a callback to be applied after an entry has been instantiated
     * @param string $id
     * @param Closure $fn
     * @param string|null $name
     * @return self
     */
    public function callback(string $id, Closure $fn, ?string $name = null): self
    {
        assert(!$this->locked);
        if ($name === null) {
            $name = (string)count($this->callbacksFor($id));
        }
        $this->callbacks[$id][$name] = $fn;
        return $this;
    }

    /**
     * Retrieve callbacks for an entry
     * @param string $id
     * @return array<string,callable>
     */
    public function callbacksFor(string $id): array
    {
        return $this->callbacks[$id] ?? [];
    }

    /**
     * Nicely close up definitions, since most IDE wants the ; on the same line
     * Eg:
     * $this->definitions()
     *   ->...
     *   ->lock();
     * Don't allow further edit once called (soft checks, not strictly enforced)
     * @return self
     */
    public function lock(): self
    {
        $this->locked = true;
        return $this;
    }

    public function unlock(): self
    {
        $this->locked = false;
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }
}

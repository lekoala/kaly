<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;

/**
 * This class is a helper to build definitions for the Di Container
 *
 * Some advanced features (parameters, callbacks) are only available using this helper
 */
class Definitions
{
    /**
     * @var array<string,class-string|object|null>
     */
    protected array $value = [];

    /**
     * @var array<string,array<string,callable>>
     */
    protected array $callbacks = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $parameters = [];

    /**
     * @var array<class-string,array<string,callable>>
     */
    protected array $resolvers = [];

    protected bool $locked = false;

    /**
     * You can create the definitions with a basic array that map interfaces/ids to a class name or a closure
     * @param array<mixed>|Definitions|null $definitions
     */
    public function __construct(array|Definitions|null $definitions = null)
    {
        if ($definitions) {
            if (is_array($definitions)) {
                foreach ($definitions as $k => $v) {
                    assert(is_string($k));
                    assert(is_object($v) || $v === null || (is_string($v) && class_exists($v)));
                    $this->set($k, $v);
                }
            } else {
                $this->merge($definitions);
            }
        }
    }

    public function merge(Definitions $definitions): void
    {
        //@phpstan-ignore-next-line
        $this->value = array_merge($this->value, $this->getData('value'));
        foreach (['callbacks', 'parameters', 'resolvers'] as $k) {
            $this->mergeDefinitionsData($definitions, $k);
        }
    }

    public function mergeDefinitionsData(Definitions $definitions, string $k): void
    {
        $data = $definitions->getData($k);
        foreach ($data as $key => $values) {
            //@phpstan-ignore-next-line
            $this->$k[$key] = array_merge($this->$k[$key] ?? [], $values);
        }
    }

    /**
     * @param string $k
     * @return array<mixed>
     */
    public function getData(string $k): array
    {
        assert(property_exists($this, $k));
        return $this->$k;
    }

    public function sort(): void
    {
        ksort($this->value);
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
        return array_key_exists($id, $this->value);
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
     * @return mixed
     */
    public function get(string $id): mixed
    {
        return $this->value[$id] ?? null;
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
        $this->value[$id] = $value;
        return $this;
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
                $this->value[$interface] = $obj;
            }
        }
        $parents = class_parents($obj);
        foreach ($parents as $parent) {
            if (!$this->has($parent)) {
                $this->value[$parent] = $obj;
            }
        }
        $this->value[$obj::class] = $obj;
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
        assert(class_exists($class), "$class does not exist");
        if ($interface === null) {
            $interfaces = class_implements($class);
            if (count($interfaces) === 1) {
                $interface = key($interfaces);
            }
        }
        assert($interface !== null && interface_exists($interface), "$interface does not exist");
        if (!empty($parameters)) {
            $this->parameters($class, ...$parameters);
        }
        return $this->set($interface, $class);
    }

    /**
     * Define how to map constructors arguments when building objects of a given class
     *
     * @param class-string $class
     * @param string $key Can be a variable name, a fully qualified class name (or * when using a closure)
     * @param callable $value fn(string $constructor, string $class)
     * @return self
     */
    public function resolve(string $class, string $key, callable $value): self
    {
        assert(!$this->locked);
        $this->resolvers[$class][$key] = $value;
        return $this;
    }

    /**
     * @param class-string $class
     * @return array<string,callable>
     */
    public function getResolvers(string $class): array
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
     * @param string $id
     * @return array<string,mixed>
     */
    public function getParameters(string $id): array
    {
        return $this->parameters[$id] ?? [];
    }

    /**
     * Provide a callback to be applied after an entry has been instantiated
     * @param string $id
     * @param Closure $fn
     * @param string|null $name
     * @return self
     */
    public function callback(string $id, Closure $fn, string $name = null): self
    {
        assert(!$this->locked);
        if ($name === null) {
            $name = (string)count($this->getCallbacks($id));
        }
        $this->callbacks[$id][$name] = $fn;
        return $this;
    }

    /**
     * Retrieve callbacks for an entry
     * @param string $id
     * @return array<string,callable>
     */
    public function getCallbacks(string $id): array
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
     * @return void
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    public function unlock(): void
    {
        $this->locked = false;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }
}

<?php

declare(strict_types=1);

namespace Kaly\Util;

use ReflectionParameter;
use ReflectionObject;
use ReflectionClass;
use ReflectionProperty;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use Psr\Container\ContainerInterface;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/Reflection.php
 */
final class Refl
{
    /**
     * Get a @ something variable from a docblock property
     */
    public static function getDocVariable(ReflectionProperty $prop, string $var, ?string $default = null): ?string
    {
        $doc = $prop->getDocComment();
        if (!$doc) {
            return $default;
        }
        $matches = [];
        preg_match("/@$var (.*)/", $doc, $matches);
        if (isset($matches[1])) {
            return trim($matches[1]);
        }
        return $default;
    }

    /**
     * Update a property from an object even if it's not accessible
     */
    public static function updateProp(object $obj, string $prop, mixed $val): void
    {
        $refObject = new ReflectionObject($obj);
        $refProperty = $refObject->getProperty($prop);
        $refProperty->setAccessible(true);
        $refProperty->setValue($obj, $val);
    }

    /**
     * Update a property from an object even if it's not accessible using a callback
     */
    public static function updatePropCb(object $obj, string $prop, callable $cb): void
    {
        $refObject = new ReflectionObject($obj);
        $refProperty = $refObject->getProperty($prop);
        $refProperty->setAccessible(true);
        $refProperty->setValue($obj, $cb($refProperty->getValue($obj)));
    }

    /**
     * Get a property from an object even if it's not accessible
     */
    public static function getProp(object $obj, string $prop): mixed
    {
        $refObject = new ReflectionObject($obj);
        $refProperty = $refObject->getProperty($prop);
        $refProperty->setAccessible(true);
        return $refProperty->getValue($obj);
    }

    /**
     * Call a method from an object even if it's not accessible
     */
    public static function callMethod(object $obj, string $method): mixed
    {
        $refObject = new ReflectionObject($obj);
        $refMethod = $refObject->getMethod($method);
        $refMethod->setAccessible(true);
        return $refMethod->invoke($obj);
    }

    /**
     * Get a static property from a class even if it's not accessible
     * @param class-string $class
     */
    public static function getStaticProp(string $class, string $prop): mixed
    {
        $refClass = new ReflectionClass($class);
        return $refClass->getStaticPropertyValue($prop);
    }

    /**
     * Update a static property from a class even if it's not accessible
     * @param class-string $class
     */
    public static function updateStaticProp(string $class, string $prop, mixed $val): void
    {
        $refClass = new ReflectionClass($class);
        $refClass->setStaticPropertyValue($prop, $val);
    }

    /**
     * Get methods on the current class (without its ancestry)
     * @return array<string>
     */
    public static function ownMethods(string|object $class): array
    {
        $array1 = get_class_methods($class);
        if ($parent_class = get_parent_class($class)) {
            $array2 = get_class_methods($parent_class);
            $array3 = array_diff($array1, $array2);
        } else {
            $array3 = $array1;
        }
        return $array3;
    }

    /**
     * Get reflection class from a param easily in php 8
     * @link https://php.watch/versions/8.0/deprecated-reflectionparameter-methods#getClass
     */
    public static function getParameterClass(ReflectionParameter $param): ?ReflectionClass //@phpstan-ignore-line
    {
        foreach (self::getParameterTypes($param) as $type) {
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                //@phpstan-ignore-next-line
                return new ReflectionClass($type->getName());
            }
        }
        return null;
    }

    /**
     * @param ReflectionParameter $param
     * @return array<ReflectionNamedType|ReflectionIntersectionType>
     */
    public static function getParameterTypes(ReflectionParameter $param): array
    {
        $reflectionType = $param->getType();

        if (!$reflectionType) {
            return [];
        }

        //@phpstan-ignore-next-line
        return $reflectionType instanceof ReflectionUnionType
            ? $reflectionType->getTypes()
            : [$reflectionType];
    }

    public static function valueMatchType(mixed $value, ?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $t) {
                $match = self::valueMatchType($value, $t);
                // It needs to match at least one type
                if ($match && $type instanceof ReflectionUnionType) {
                    return true;
                }
                // It needs to match all types
                if (!$match && $type instanceof ReflectionIntersectionType) {
                    return false;
                }
            }
            // It didn't return, so it's valid
            if ($type instanceof ReflectionIntersectionType) {
                return true;
            }
        }
        if ($type instanceof ReflectionNamedType) {
            if ($type->allowsNull() && $value === null) {
                return true;
            }
            if ($type->isBuiltin()) {
                // get_debug_type() function returns the exact types that you use in scalar typing.
                return get_debug_type($value) === $type->getName();
            }
            if (is_object($value)) {
                // works for instances or interfaces
                return is_a($value, $type->getName());
            }
        }
        return false;
    }


    /**
     * Given an array of ReflectionParameters, returns resolved parameters
     * @param ReflectionParameter[] $parameters
     * @param array<mixed> $arguments
     * @param ?ContainerInterface $container
     * @return array<mixed>
     */
    public static function resolveParameters(array $parameters, array $arguments, ?ContainerInterface $container = null): array
    {
        // If we have an int indexed array, arguments are positional
        // Use named keys if no arguments are provided
        $isPositional = count($arguments) === 0 ? false : array_is_list($arguments);

        // Store resolved parameters
        $args = [];
        $count = -1;
        foreach ($parameters as $parameter) {
            $count++;

            // Last argument is variadic
            if ($parameter->isVariadic()) {
                // merge remaining arguments
                $args = array_merge($args, array_slice($arguments, $count));
                break;
            }

            $paramType = $parameter->getType();
            $name = $parameter->getName();

            // Check if argument is already provided, including null values
            $argumentKey = $isPositional ? $count : $name;
            $isProvided = array_key_exists($argumentKey, $arguments);

            if ($isProvided) {
                $providedArg = $arguments[$argumentKey];

                // Provided argument doesn't match type
                assert(Refl::valueMatchType($providedArg, $paramType), "parameter `$name` doesn't support " . get_debug_type($providedArg));

                $args[$argumentKey] = $providedArg;
                continue;
            }

            $defaultValue = null;

            // Or resolve using the container for any valid type
            $types = Refl::getParameterTypes($parameter);
            foreach ($types as $type) {
                if ($type instanceof ReflectionNamedType) {
                    $name = $type->getName();
                    $isBuiltIn = $type->isBuiltin();

                    // It's a built-in value
                    if ($isBuiltIn) {
                        // and the parameter doesn't allow null
                        if (!$parameter->allowsNull() && $defaultValue === null) {
                            $defaultValue = self::defaultTypeValue($type);
                        }
                        continue;
                    }

                    // The container must use the class or interface name as ID.
                    if ($container && $container->has($name)) {
                        $args[$argumentKey] = $container->get($name);
                        break;
                    }
                }
            }
            // A value was found in the container
            if (isset($args[$argumentKey])) {
                continue;
            }

            // In priority, use code provided default
            if ($parameter->isDefaultValueAvailable()) {
                $args[$argumentKey] = $parameter->getDefaultValue();
                continue;
            }

            // We can pass null
            if ($parameter->allowsNull() && $defaultValue === null) {
                $args[$argumentKey] = null;
                continue;
            }
            // Or provide default value
            if ($defaultValue !== null) {
                $args[$argumentKey] = $defaultValue;
                continue;
            }
        }

        return $args;
    }

    /**
     * Creates a default value for built in types
     * @param ReflectionNamedType|null $type
     * @return mixed
     */
    public static function defaultTypeValue(?ReflectionNamedType $type): mixed
    {
        if (!$type || !$type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        // Provide default built in value if no default is available
        // Built in is : string, float, bool, int, iterable, mixed, array
        return match ($name) {
            'array' =>  [],
            'string' => '',
            'bool' => false,
            'int' => 0,
            'float' => 0.0,
            default => null,
        };
    }

    /**
     * Get a class name from a string or object
     */
    public static function getClassName(string|object $class): string
    {
        if (is_object($class)) {
            $class = $class::class;
        }
        return $class;
    }

    /**
     * Get a class name without namespace
     */
    public static function getShortClassName(string|object $class): string
    {
        $class = self::getClassName($class);
        $parts = explode("\\", $class);
        $result = array_pop($parts);
        assert(is_string($result));
        return $result;
    }

    /**
     * Get a class namespace
     * @param class-string|object $class
     */
    public static function getClassNamespace(string|object $class): string
    {
        $class = self::getClassName($class);
        $parts = explode("\\", $class);
        array_pop($parts);
        return implode("\\", $parts);
    }

    /**
     * Sanitise a model class' name for inclusion in a link
     */
    public static function sanitiseClassName(string $class): string
    {
        return str_replace('\\', '-', $class);
    }

    /**
     * Unsanitise a model class' name from a URL param
     */
    public static function unsanitiseClassName(string $class): string
    {
        return str_replace('-', '\\', $class);
    }
}

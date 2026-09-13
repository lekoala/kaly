<?php

declare(strict_types=1);

namespace Kaly\Util;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Reflection helpers backing dependency injection and route dispatch.
 *
 * Reference implementation: nette/utils Reflection.
 *
 * @link https://github.com/nette/utils/blob/master/src/Utils/Reflection.php
 */
final class Refl
{
    /**
     * Resolve the class of a parameter, skipping builtin types.
     *
     * See https://php.watch/versions/8.0/deprecated-reflectionparameter-methods#getClass.
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
     * List all accepted types of a parameter, empty when untyped.
     *
     * @return array<ReflectionNamedType|ReflectionIntersectionType>
     */
    public static function getParameterTypes(ReflectionParameter $param): array
    {
        $reflectionType = $param->getType();

        if (!$reflectionType) {
            return [];
        }

        //@phpstan-ignore-next-line
        return $reflectionType instanceof ReflectionUnionType ? $reflectionType->getTypes() : [$reflectionType];
    }

    /**
     * Check whether a value satisfies a reflection type.
     */
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
                if (!Refl::valueMatchType($providedArg, $paramType)) {
                    throw new InvalidArgumentException("parameter `{$name}` doesn't support " . get_debug_type($providedArg));
                }

                $args[$argumentKey] = $providedArg;
                continue;
            }

            $defaultValue = null;

            // Or resolve using the container for any valid type
            $types = Refl::getParameterTypes($parameter);
            foreach ($types as $type) {
                if (!$type instanceof ReflectionNamedType) {
                    continue;
                }

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
            'array' => [],
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
}

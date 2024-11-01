<?php

declare(strict_types=1);

namespace Kaly\Util;

use ReflectionParameter;
use ReflectionObject;
use ReflectionClass;
use Exception;
use ReflectionProperty;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;

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
    public static function getReflectionClass(ReflectionParameter $param): ?ReflectionClass //@phpstan-ignore-line
    {
        foreach (self::getAllTypes($param) as $type) {
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                //@phpstan-ignore-next-line
                return new ReflectionClass($type->getName());
            }
        }
        return null;
    }

    /**
     * @param ReflectionParameter $param
     * @return array<ReflectionType>
     */
    public static function getAllTypes(ReflectionParameter $param): array
    {
        $reflectionType = $param->getType();

        if (!$reflectionType) {
            return [];
        }

        return $reflectionType instanceof ReflectionUnionType
            ? $reflectionType->getTypes()
            : [$reflectionType];
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
    public static function getClassWithoutNamespace(string|object $class): string
    {
        $class = self::getClassName($class);
        $parts = explode("\\", $class);
        $result = array_pop($parts);
        if (!$result) {
            throw new Exception("Could not get class");
        }
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

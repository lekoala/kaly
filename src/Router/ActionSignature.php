<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Di\Reflection;
use Kaly\Ex;
use Kaly\Http\Input\RequestInput;
use Kaly\Util\Cast;
use ReflectionMethod;
use ReflectionParameter;

/**
 * What the framework reads on a controller action's signature.
 *
 * One home for the rules every route source must agree on — declared tables
 * and convention routing share them, so a method can never be routable one
 * way and refused the other.
 */
final class ActionSignature
{
    /**
     * The admissibility rule: an action is a public, non-static method.
     * Magic methods are never routable, except __invoke which IS the action.
     */
    public static function isAdmissible(ReflectionMethod $method): bool
    {
        $name = $method->getName();
        if (str_starts_with($name, '__') && $name !== RouterInterface::FALLBACK_ACTION) {
            return false;
        }
        return $method->isPublic() && !$method->isStatic();
    }

    /**
     * The RequestInput class a parameter asks for, or null when it is a
     * url-satisfied scalar.
     *
     * @return class-string<RequestInput>|null
     */
    public static function inputClassOf(ReflectionParameter $param): ?string
    {
        $name = Reflection::getParameterTypeName($param);
        if ($name === null || !is_a($name, RequestInput::class, true)) {
            return null;
        }
        /** @var class-string<RequestInput> $name */
        return $name;
    }

    /**
     * The RequestInput class an action expects, if any. There is at most one
     * and it is always the last parameter — the dispatcher supplies it, the
     * url never reaches it.
     *
     * @param ReflectionParameter[] $actionParams
     * @return class-string<RequestInput>|null
     */
    public static function inputOf(array $actionParams, string $action, string $class): ?string
    {
        $last = count($actionParams) - 1;
        $inputClass = null;
        foreach ($actionParams as $i => $actionParam) {
            $name = self::inputClassOf($actionParam);
            if ($name === null) {
                continue;
            }
            if ($i !== $last) {
                throw new Ex(
                    RequestInput::class
                    . " parameter '{$actionParam->getName()}' must be the last parameter"
                    . " of action '{$action}' on '{$class}'",
                );
            }
            $inputClass = $name;
        }
        return $inputClass;
    }

    /**
     * Does this parameter expect an object? Such a parameter can never be
     * satisfied by a url segment.
     */
    public static function hasClassType(ReflectionParameter $param): bool
    {
        return Reflection::getParameterTypeName($param) !== null;
    }

    /**
     * Coerce a url segment to the builtin type a parameter asks for.
     *
     * Coercion is strict: an invalid value does not match the route and
     * raises a RouteNotFoundException (404) instead of flowing into the
     * action.
     *
     * @return int|float|bool|array<int,string>|string
     */
    public static function coerce(string $type, string $value): int|float|bool|array|string
    {
        return match ($type) {
            'int' => Cast::intOrNull($value) ?? throw new RouteNotFoundException("Invalid integer value '{$value}'"),
            'float' => Cast::floatOrNull($value) ?? throw new RouteNotFoundException("Invalid float value '{$value}'"),
            'bool' => Cast::boolOrNull($value) ?? throw new RouteNotFoundException("Invalid boolean value '{$value}'"),
            'array' => explode(',', $value),
            default => $value,
        };
    }
}

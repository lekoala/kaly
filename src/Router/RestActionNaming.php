<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * The rest-style action suffix convention.
 *
 * The HTTP method is appended to the action name (eg: POST => changePost) to
 * avoid confusion with getters. A url naming an action suffixed by another
 * HTTP verb must not run for the current request.
 */
final class RestActionNaming
{
    /**
     * Convert an HTTP method to the action suffix convention (eg: POST => Post).
     */
    public static function methodSuffix(string $method): string
    {
        return ucfirst(strtolower($method));
    }

    /**
     * Return the HTTP verb suffix of an action name, if any.
     */
    public static function verbSuffix(string $action): ?string
    {
        if (preg_match('/(Post|Put|Patch|Delete|Head|Options|Get)$/', $action, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Strip the HTTP verb suffix of an action name, if any.
     */
    public static function stripVerbSuffix(string $action): string
    {
        $suffix = self::verbSuffix($action);
        if ($suffix === null) {
            return $action;
        }
        return substr($action, 0, -strlen($suffix));
    }
}

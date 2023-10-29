<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;

/**
 * @link https://github.com/vlucas/phpdotenv#putenv-and-getenv
 */
class Env
{
    /**
     * Load the .env and add the values to the $_ENV
     *
     * @throws RuntimeException
     */
    public static function load(string $envFile, bool $redefine = false): void
    {
        $result = parse_ini_file($envFile);
        if (!$result) {
            throw new RuntimeException("Failed to parse `$envFile`");
        }
        foreach ($result as $k => $v) {
            // Make sure that we are not overwriting variables
            if (isset($_ENV[$k]) && !$redefine) {
                throw new RuntimeException("Could not redefine `$k` in ENV");
            }
            // Store in $_ENV as string
            $_ENV[$k] = $v;
        }
    }

    /**
     * Get typed value of an environment variable.
     */
    public static function get(string $key, string|bool|null $default = null): string|bool|null
    {
        $v = $_ENV[$key] ?? $default;
        return match (strtolower($v)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $v,
        };
    }

    /**
     * Set value of an environment variable as string.
     */
    public static function set(string $key, string $value)
    {
        $_ENV[$key] = $value;
    }

    /**
     * Get `string` value of an environment variable.
     *
     * @throws RuntimeException
     */
    public static function getString(string $key, string $default = ''): string
    {
        $value = static::get($key, $default);
        if (!is_string($value)) {
            throw new RuntimeException("Env variable `$key` is not a string");
        }
        return $value;
    }

    /**
     * Get `string` or `null` value of an environment variable.
     *
     * @throws RuntimeException
     */
    public static function getNullableString(string $key, string|null $default = null): string|null
    {
        $value = static::get($key, $default);
        if (!is_string($value) && !is_null($value)) {
            throw new RuntimeException("Env variable `$key` is not a nullable string");
        }
        return $value;
    }

    /**
     * Get `bool` value of an environment variable.
     *
     * @throws RuntimeException
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = static::get($key, $default);
        if (!is_bool($value)) {
            throw new RuntimeException("Env variable `$key` is not a bool");
        }
        return $value;
    }

    /**
     * Get `bool` or `null` value of an environment variable.
     *
     * @throws RuntimeException
     */
    public static function getNullableBool(string $key, bool|null $default = null): bool|null
    {
        $value = static::get($key, $default);
        if (!is_bool($value) && !is_null($value)) {
            throw new RuntimeException("Env variable `$key` is not a nullable bool");
        }
        return $value;
    }
}

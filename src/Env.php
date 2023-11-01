<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;

/**
 * All values are stored as string in $_ENV
 * Types are converted when getting the values. This is due to the fact
 * that values provided by the environment are provided as string in most cases
 *
 * @link https://github.com/vlucas/phpdotenv#putenv-and-getenv
 */
class Env
{
    /**
     * Load the .env and add the values to the $_ENV
     *
     * @throws RuntimeException
     */
    public static function load(string $envFile, bool $redefine = false): array
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
        return $result;
    }

    /**
     * Get typed value of an environment variable.
     */
    public static function get(string $key, string|bool|null $default = null): string|bool|null
    {
        $v = $_ENV[$key] ?? $default;
        if (!is_string($v)) {
            // It is already typed (eg: if you set manually $_ENV['some_value'] = true)
            return $v;
        }
        return match (strtolower($v)) {
            'true' => true,
            'false' => false,
            'null' => $default,
            '' => $default,
            default => $v,
        };
    }

    /**
     * Get all typed values from environment
     */
    public static function getAll(): array
    {
        $arr = [];
        foreach ($_ENV as $key => $v) {
            $arr[$key] = self::get($key);
        }
        return $arr;
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
     * Empty or null values are converted to '' or set default
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
    public static function getNullableString(string $key, ?string $default = null): ?string
    {
        $value = static::get($key, $default);
        if (!is_string($value) && !is_null($value)) {
            throw new RuntimeException("Env variable `$key` is not a nullable string");
        }
        return $value;
    }

    /**
     * Get `int` value of an environment variable.
     * Empty or null values are converted to 0 or set default
     *
     * @throws RuntimeException
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = static::get($key, (string)$default);
        if (is_numeric($value)) {
            $value = intval($value);
        }
        if (!is_int($value)) {
            throw new RuntimeException("Env variable `$key` is not a int");
        }
        return $value;
    }

    /**
     * Get `int` value of an environment variable.
     *
     * @throws RuntimeException
     */
    public static function getNullableInt(string $key, ?int $default = null): ?int
    {
        $value = static::get($key, (string)$default);
        if (!is_int($value) && !is_null($value)) {
            throw new RuntimeException("Env variable `$key` is not a int");
        }
        return $value;
    }

    /**
     * Get `bool` value of an environment variable.
     * Empty or null values are converted to false or set default
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
    public static function getNullableBool(string $key, ?bool $default = null): ?bool
    {
        $value = static::get($key, $default);
        if (!is_bool($value) && !is_null($value)) {
            throw new RuntimeException("Env variable `$key` is not a nullable bool");
        }
        return $value;
    }
}

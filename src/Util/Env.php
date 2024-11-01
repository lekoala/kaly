<?php

declare(strict_types=1);

namespace Kaly\Util;

use RuntimeException;

/**
 * All values are stored as string in $_ENV
 * Types are converted when getting the values. This is due to the fact
 * that values provided by the environment are provided as string in most cases
 *
 * @link https://github.com/vlucas/phpdotenv#putenv-and-getenv
 */
final class Env
{
    /**
     * Load the .env and add the values to the $_ENV
     *
     * @return array<string,string>
     * @throws RuntimeException
     */
    public static function load(string $envFile, bool $overwrite = false): array
    {
        $result = parse_ini_file($envFile);
        if (!$result) {
            throw new RuntimeException("Failed to parse `$envFile`");
        }
        foreach ($result as $k => $v) {
            // Make sure that we are not overwriting variables
            if (isset($_ENV[$k]) && !$overwrite) {
                throw new RuntimeException("Could not overwrite `$k` in ENV");
            }
            // Store in $_ENV as string
            $_ENV[$k] = $v;
        }
        return $result;
    }


    /**
     * Get typed value of an environment variable.
     * @param string $key
     * @param string|bool|int|null|null $default
     * @return mixed
     */
    public static function get(string $key, string|bool|int|null $default = null): mixed
    {
        $v = $_ENV[$key] ?? $default;
        if (!is_string($v)) {
            // It is already typed (eg: if you set manually $_ENV['some_value'] = true)
            return $v;
        }
        // Convert null or empty values to default
        return match (strtolower($v)) {
            'null' => $default,
            '' => $default,
            default => $v,
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function getAll(): array
    {
        return $_ENV;
    }

    /**
     * Check value of an environment variable exists.
     */
    public static function has(string $key): bool
    {
        return isset($_ENV[$key]);
    }

    /**
     * Set value of an environment variable as string.
     */
    public static function set(string $key, string $value): void
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
        $value = static::get($key) ?? $default;
        if (!is_string($value)) {
            throw new RuntimeException("Env variable `$key` is not a string");
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
        $value = static::get($key) ?? $default;
        if (!is_int($value)) {
            if (is_numeric($value)) {
                $value = intval($value);
            } else {
                throw new RuntimeException("Env variable `$key` is not a int");
            }
        }
        return $value;
    }

    /**
     * Get `float` value of an environment variable.
     * Empty or null values are converted to 0 or set default
     *
     * @throws RuntimeException
     */
    public static function getFloat(string $key, float $default = 0): float
    {
        $value = static::get($key) ?? $default;
        if (!is_float($value)) {
            if (is_numeric($value)) {
                $value = floatval($value);
            } else {
                throw new RuntimeException("Env variable `$key` is not a float");
            }
        }
        return $value;
    }

    /**
     * Get `array` value of an environment variable.
     * Empty or null values are converted to an empty array
     *
     * @param array<mixed> $default
     * @return array<mixed>
     * @throws RuntimeException
     */
    public static function getArray(string $key, array $default = [], string $separator = ";"): array
    {
        $value = static::get($key) ?? $default;
        if (is_string($value) && $separator) {
            $value = array_map('trim', explode($separator, $value));
        }
        if (!is_array($value)) {
            throw new RuntimeException("Env variable `$key` is not an array");
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
        $value = static::get($key) ?? $default;
        if (is_string($value)) {
            $value = match (strtolower($value)) {
                '1' => true,
                'true' => true,
                '0' => false,
                'false' => false,
                default => $value,
            };
        }
        if (!is_bool($value)) {
            throw new RuntimeException("Env variable `$key` is not a bool");
        }
        return $value;
    }
}

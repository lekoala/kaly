<?php

declare(strict_types=1);

namespace Kaly\Util;

use RuntimeException;

/**
 * Values loaded or written by Kaly live in $_ENV as strings.
 * Values from the host process are also read via getenv(), so a real
 * environment variable is visible even when `variables_order` does not
 * contain `E` (the php.ini-production/development default) and $_ENV
 * stays empty. Kaly never calls putenv().
 *
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
     * The file is parsed in raw mode so that INI specific conversions and
     * interpolations do not leak into the environment. Only valid environment
     * variable names with string values are accepted.
     *
     * Precedence is: process environment first, .env only fills the gaps.
     * With `$overwrite = false` (default) an already defined key is left
     * untouched; with `$overwrite = true` the .env value replaces what Kaly
     * sees via Env. Since Kaly never calls putenv(), overwriting only affects
     * the Env view (`$_ENV`): a third party calling `getenv()` directly keeps
     * seeing the original process value.
     *
     * @return array<string,string> Only the keys actually loaded
     * @throws RuntimeException
     */
    public static function load(string $envFile, bool $overwrite = false): array
    {
        $result = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if ($result === false) {
            throw new RuntimeException("Failed to parse `{$envFile}`");
        }
        $env = [];
        foreach ($result as $key => $value) {
            // Only accept valid environment variable names
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
                throw new RuntimeException("Invalid environment variable name `{$key}`");
            }

            // A .env entry is a name and a text value. INI array syntax would
            // produce arrays (eg: FOO[]=bar), which we reject.
            if (!is_string($value)) {
                throw new RuntimeException("Environment variable `{$key}` must be a string");
            }

            // The real environment wins: a key already defined (even
            // explicitly set to null in $_ENV, or only visible via getenv)
            // is skipped unless overwrite is requested.
            if (!$overwrite && static::has($key)) {
                continue;
            }

            // Store in $_ENV as string
            $_ENV[$key] = $value;
            $env[$key] = $value;
        }
        return $env;
    }

    /**
     * Get typed value of an environment variable.
     *
     * @param string|bool|int|null $default Returned when the value is missing, null or empty
     * @return mixed The typed value or $default
     */
    public static function get(string $key, string|bool|int|null $default = null): mixed
    {
        // array_key_exists (not ?? / isset) so that an explicit null in
        // $_ENV stays "defined" and does not fall through to getenv().
        if (array_key_exists($key, $_ENV)) {
            $v = $_ENV[$key];
        } else {
            $v = getenv($key);
            if ($v === false) {
                $v = $default;
            }
        }
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
     * Return all environment values.
     *
     * Process values (via getenv) are merged first so that $_ENV — where
     * Kaly stores .env entries and Env::set() values — intentionally wins.
     *
     * @return array<string,mixed>
     */
    public static function getAll(): array
    {
        $process = getenv();
        if (!is_array($process)) {
            $process = [];
        }
        /** @var array<string,mixed> $merged */
        $merged = array_replace($process, $_ENV);
        return $merged;
    }

    /**
     * Check value of an environment variable exists.
     *
     * An explicit null in $_ENV counts as defined, mirroring load().
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, $_ENV) || getenv($key) !== false;
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
            throw new RuntimeException("Env variable `{$key}` is not a string");
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
                throw new RuntimeException("Env variable `{$key}` is not a int");
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
                throw new RuntimeException("Env variable `{$key}` is not a float");
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
    public static function getArray(string $key, array $default = [], string $separator = ';'): array
    {
        $value = static::get($key) ?? $default;
        if (is_string($value) && $separator) {
            $value = array_map('trim', explode($separator, $value));
        }
        if (!is_array($value)) {
            throw new RuntimeException("Env variable `{$key}` is not an array");
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
            throw new RuntimeException("Env variable `{$key}` is not a bool");
        }
        return $value;
    }
}

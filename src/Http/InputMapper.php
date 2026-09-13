<?php

declare(strict_types=1);

namespace Kaly\Http;

use BackedEnum;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds the typed input of an action from the query string and the parsed body.
 *
 * The promoted constructor of the input is the schema: there is nothing to
 * configure and no attribute to add. Mapping only answers "can this data be
 * represented by this type?" - the business rules belong to the input itself.
 *
 * @see docs/input.md
 */
class InputMapper implements InputMapperInterface
{
    /**
     * @template T of RequestInput
     * @param class-string<T> $class
     * @return T
     */
    public function map(ServerRequestInterface $request, string $class): RequestInput
    {
        $data = $this->collect($request);

        $arguments = [];
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            // An empty value is not a value: a form always posts its empty
            // fields, and "" is only meaningful for a string.
            $missing = !array_key_exists($name, $data) || $data[$name] === '' && !$this->isType($parameter, 'string');

            if ($missing) {
                if ($parameter->isDefaultValueAvailable()) {
                    // Let the constructor apply its own default
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $arguments[$name] = null;
                    continue;
                }
                throw new InputException("'{$name}' is required");
            }

            $arguments[$name] = $this->coerce($parameter, $data[$name]);
        }

        $input = new $class(...$arguments);

        if ($input instanceof ValidatableInput) {
            $input->validate();
        }

        return $input;
    }

    /**
     * The query string and the body are merged without a hidden winner: a key
     * sent in both is fine as long as both carry the same value.
     *
     * @return array<string,mixed>
     */
    protected function collect(ServerRequestInterface $request): array
    {
        $data = [];
        foreach ($request->getQueryParams() as $key => $value) {
            $data[(string) $key] = $value;
        }

        $body = $request->getParsedBody();
        if (is_object($body)) {
            $body = get_object_vars($body);
        }
        if (!is_array($body)) {
            return $data;
        }

        foreach ($body as $key => $value) {
            $key = (string) $key;
            if (array_key_exists($key, $data) && !$this->equivalent($data[$key], $value)) {
                throw new InputException("'{$key}' was sent in the query and in the body with different values");
            }
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * A client repeating a value is fine, a client contradicting itself is not.
     * Scalars are compared as text since the query string has no types.
     */
    protected function equivalent(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }
        if (is_array($a) && is_array($b)) {
            return $a === $b;
        }
        return $a === $b;
    }

    protected function coerce(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if ($type === null) {
            return $value;
        }
        if (!$type instanceof ReflectionNamedType) {
            throw $this->unsupported($parameter);
        }
        if ($value === null) {
            if (!$type->allowsNull()) {
                throw new InputException("'{$parameter->getName()}' cannot be null");
            }
            return null;
        }

        $name = $type->getName();

        if (!$type->isBuiltin()) {
            if (is_a($name, BackedEnum::class, true)) {
                // is_a() just proved $name is a backed enum class-string
                /** @var class-string<BackedEnum> $name */
                return $this->toEnum($parameter, $name, $value);
            }
            throw $this->unsupported($parameter);
        }

        return match ($name) {
            'mixed' => $value,
            'string' => $this->toString($parameter, $value),
            'int' => $this->toInt($parameter, $value),
            'float' => $this->toFloat($parameter, $value),
            'bool' => $this->toBool($parameter, $value),
            'array' => $this->toArray($parameter, $value),
            default => throw $this->unsupported($parameter),
        };
    }

    protected function toString(ReflectionParameter $parameter, mixed $value): string
    {
        if (!is_scalar($value)) {
            throw $this->invalid($parameter, 'a string', $value);
        }
        return (string) $value;
    }

    protected function toInt(ReflectionParameter $parameter, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $int = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($int === false) {
            throw $this->invalid($parameter, 'an integer', $value);
        }
        return $int;
    }

    protected function toFloat(ReflectionParameter $parameter, mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $float = is_string($value) ? filter_var($value, FILTER_VALIDATE_FLOAT) : false;
        if ($float === false) {
            throw $this->invalid($parameter, 'a number', $value);
        }
        return $float;
    }

    protected function toBool(ReflectionParameter $parameter, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $bool = is_scalar($value) ? filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($bool === null) {
            throw $this->invalid($parameter, 'a boolean', $value);
        }
        return $bool;
    }

    /**
     * @return array<mixed>
     */
    protected function toArray(ReflectionParameter $parameter, mixed $value): array
    {
        // Only a real array: use ?tag[]=a&tag[]=b, never a separator convention
        if (!is_array($value)) {
            throw $this->invalid($parameter, 'an array', $value);
        }
        return $value;
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    protected function toEnum(ReflectionParameter $parameter, string $enum, mixed $value): BackedEnum
    {
        if ($value instanceof $enum) {
            // $enum holds a backed enum class-string, so $value is one of its cases
            /** @var BackedEnum $value */
            return $value;
        }
        if (!is_scalar($value)) {
            throw $this->invalid($parameter, $enum, $value);
        }

        // A backed enum is either int or string backed, never both
        $backing = (new ReflectionEnum($enum))->getBackingType();
        $scalar =
            $backing instanceof ReflectionNamedType && $backing->getName() === 'int' ? $this->toInt($parameter, $value) : (string) $value;

        $case = $enum::tryFrom($scalar);
        if ($case === null) {
            throw $this->invalid($parameter, $enum, $value);
        }
        return $case;
    }

    protected function isType(ReflectionParameter $parameter, string $name): bool
    {
        $type = $parameter->getType();
        return $type instanceof ReflectionNamedType && $type->getName() === $name;
    }

    protected function invalid(ReflectionParameter $parameter, string $expected, mixed $value): InputException
    {
        return new InputException(sprintf("'%s' must be %s, got %s", $parameter->getName(), $expected, get_debug_type($value)));
    }

    /**
     * An input property the mapper cannot build is a programming error, not a
     * bad request: it would fail for every single client.
     */
    protected function unsupported(ReflectionParameter $parameter): LogicException
    {
        $class = $parameter->getDeclaringClass();
        return new LogicException(sprintf(
            "Input property '%s' of %s has an unsupported type %s. Supported: string, int, float, bool, array, backed enums.",
            $parameter->getName(),
            $class !== null ? $class->getName() : '(unknown)',
            (string) $parameter->getType(),
        ));
    }
}

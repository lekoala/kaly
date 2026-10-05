<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use BackedEnum;
use Kaly\Util\Cast;
use Kaly\Validation\ValidationResult;
use Kaly\Validation\Validator;
use Kaly\Validation\Violation;
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
 * represented by this type?" - the business rules belong to the input itself
 * through ValidatableInput.
 *
 * @see docs/input.md
 */
final class InputMapper implements InputMapperInterface
{
    /**
     * @template T of RequestInput
     * @param class-string<T> $class
     * @return T
     */
    public function map(ServerRequestInterface $request, string $class): RequestInput
    {
        return $this->mapResult($request, $class)->require();
    }

    /**
     * @template T of RequestInput
     * @param class-string<T> $class
     * @return InputResult<T>
     */
    public function mapResult(ServerRequestInterface $request, string $class): InputResult
    {
        [$data, $violations] = $this->merged($request);

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
                $violations[] = new Violation($name, 'required', 'required', 'This value is required', domain: 'input');
                continue;
            }

            $coerced = $this->coerce($parameter, $data[$name]);
            if ($coerced[1] !== null) {
                $violations[] = $coerced[1];
                continue;
            }
            $arguments[$name] = $coerced[0];
        }

        // Without a DTO there is nothing to validate: mapping errors alone
        // decide the 400.
        $input = $violations !== [] ? null : new $class(...$arguments);

        if ($input instanceof ValidatableInput) {
            $validator = new Validator();
            $input->validate($validator);
            $validation = $validator->result();
        } elseif ($violations !== []) {
            $validation = new ValidationResult($violations);
        } else {
            $validation = new ValidationResult();
        }

        return new InputResult($data, $input, $validation);
    }

    /**
     * The query string and the body are merged without a hidden winner: a key
     * sent in both is fine as long as both carry the same value. A conflict
     * keeps the query value and becomes a violation instead of stopping the
     * whole mapping.
     *
     * @return array{0: array<string,mixed>, 1: list<Violation>}
     */
    protected function merged(ServerRequestInterface $request): array
    {
        $data = [];
        foreach ($request->getQueryParams() as $key => $value) {
            $data[(string) $key] = $value;
        }

        $violations = [];

        $body = $request->getParsedBody();
        if (is_object($body)) {
            $body = get_object_vars($body);
        }
        if (!is_array($body)) {
            return [$data, $violations];
        }

        foreach ($body as $key => $value) {
            $key = (string) $key;
            if (array_key_exists($key, $data) && !$this->equivalent($data[$key], $value)) {
                $violations[] = new Violation(
                    $key,
                    'conflicting_values',
                    'conflicting_values',
                    'This value was provided more than once with different values',
                    domain: 'input',
                );
                continue;
            }
            $data[$key] = $value;
        }

        return [$data, $violations];
    }

    /**
     * A client repeating a value is fine, a client contradicting itself is not.
     *
     * Only values are normalized, never the structure: keys and order are
     * kept as is, scalar leaves compare as text since the query string has no
     * types, and anything else compares strictly.
     */
    protected function equivalent(mixed $a, mixed $b): bool
    {
        return $this->comparable($a) === $this->comparable($b);
    }

    private function comparable(mixed $value): mixed
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->comparable($item);
            }
            return $normalized;
        }
        return $value;
    }

    /**
     * Coerces one field, accumulating a violation instead of throwing.
     *
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function coerce(ReflectionParameter $parameter, mixed $value): array
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if ($type === null) {
            return [$value, null];
        }
        if (!$type instanceof ReflectionNamedType) {
            throw $this->unsupported($parameter);
        }
        if ($value === null) {
            if (!$type->allowsNull()) {
                return [null, new Violation($name, 'null_not_allowed', 'null_not_allowed', 'This value cannot be null', domain: 'input')];
            }
            return [null, null];
        }

        $typeName = $type->getName();

        if (!$type->isBuiltin()) {
            if (is_a($typeName, BackedEnum::class, true)) {
                // is_a() just proved $typeName is a backed enum class-string
                /** @var class-string<BackedEnum> $typeName */
                return $this->toEnum($parameter, $typeName, $value);
            }
            throw $this->unsupported($parameter);
        }

        return match ($typeName) {
            'mixed' => [$value, null],
            'string' => $this->toString($parameter, $value),
            'int' => $this->toInt($parameter, $value),
            'float' => $this->toFloat($parameter, $value),
            'bool' => $this->toBool($parameter, $value),
            'array' => $this->toArray($parameter, $value),
            default => throw $this->unsupported($parameter),
        };
    }

    /**
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toString(ReflectionParameter $parameter, mixed $value): array
    {
        if (!is_scalar($value)) {
            return [null, $this->invalid($parameter, 'type_string', 'This value must be a string')];
        }
        return [(string) $value, null];
    }

    /**
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toInt(ReflectionParameter $parameter, mixed $value): array
    {
        $coerced = Cast::intOrNull($value);
        if ($coerced === null) {
            return [null, $this->invalid($parameter, 'type_int', 'This value must be an integer')];
        }
        return [$coerced, null];
    }

    /**
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toFloat(ReflectionParameter $parameter, mixed $value): array
    {
        $coerced = Cast::floatOrNull($value);
        if ($coerced === null) {
            return [null, $this->invalid($parameter, 'type_float', 'This value must be a number')];
        }
        return [$coerced, null];
    }

    /**
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toBool(ReflectionParameter $parameter, mixed $value): array
    {
        $coerced = Cast::boolOrNull($value);
        if ($coerced === null) {
            return [null, $this->invalid($parameter, 'type_bool', 'This value must be a boolean')];
        }
        return [$coerced, null];
    }

    /**
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toArray(ReflectionParameter $parameter, mixed $value): array
    {
        // Only a real array: use ?tag[]=a&tag[]=b, never a separator convention
        if (!is_array($value)) {
            return [null, $this->invalid($parameter, 'type_array', 'This value must be an array')];
        }
        return [$value, null];
    }

    /**
     * @param class-string<BackedEnum> $enum
     * @return array{0: mixed, 1: ?Violation}
     */
    protected function toEnum(ReflectionParameter $parameter, string $enum, mixed $value): array
    {
        if ($value instanceof $enum) {
            // $enum holds a backed enum class-string, so $value is one of its cases
            /** @var BackedEnum $value */
            return [$value, null];
        }
        if (!is_scalar($value)) {
            return [null, new Violation($parameter->getName(), 'enum', 'enum', 'This value is not a valid choice', domain: 'input')];
        }

        // A backed enum is either int or string backed, never both
        $backing = (new ReflectionEnum($enum))->getBackingType();
        if ($backing instanceof ReflectionNamedType && $backing->getName() === 'int') {
            $scalar = Cast::intOrNull($value);
            if ($scalar === null) {
                return [null, $this->invalid($parameter, 'type_int', 'This value must be an integer')];
            }
        } else {
            $scalar = (string) $value;
        }

        $case = $enum::tryFrom($scalar);
        if ($case === null) {
            return [null, new Violation($parameter->getName(), 'enum', 'enum', 'This value is not a valid choice', domain: 'input')];
        }
        return [$case, null];
    }

    protected function isType(ReflectionParameter $parameter, string $name): bool
    {
        $type = $parameter->getType();
        return $type instanceof ReflectionNamedType && $type->getName() === $name;
    }

    protected function invalid(ReflectionParameter $parameter, string $code, string $fallback): Violation
    {
        return new Violation($parameter->getName(), $code, $code, $fallback, domain: 'input');
    }

    /**
     * An input property the mapper cannot build is a programming error, not a
     * bad request: it would fail for every single client.
     */
    protected function unsupported(ReflectionParameter $parameter): LogicException
    {
        $class = $parameter->getDeclaringClass();
        return new LogicException(sprintf(
            "Input property '%s' of %s has an unsupported type %s, supported: string, int, float, bool, array, backed enums",
            $parameter->getName(),
            $class !== null ? $class->getName() : '(unknown)',
            (string) $parameter->getType(),
        ));
    }
}

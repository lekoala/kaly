<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Http\InputException;
use Kaly\Http\InputMapperInterface;
use Kaly\Http\RequestInput;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A deliberately minimal stand-in for the real mapper: it only proves that the
 * dispatcher hands the request over and passes the result to the action.
 */
final class TestInputMapper implements InputMapperInterface
{
    public function map(ServerRequestInterface $request, string $class): RequestInput
    {
        $data = $request->getQueryParams();
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $data = array_merge($data, $body);
        }

        $arguments = [];
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
                if (!is_numeric($value)) {
                    throw new InputException("'{$name}' must be an integer");
                }
                $value = (int) $value;
            }
            $arguments[$name] = $value;
        }

        /** @var RequestInput */
        return new $class(...$arguments);
    }
}

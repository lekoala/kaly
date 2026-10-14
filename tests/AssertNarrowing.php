<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Assert;
use stdClass;

/**
 * Static-analysis fixture: never executed (no Test suffix), but analysed by
 * `composer phpstan` and `mago lint`. Each assertType() call fails the build
 * when the inferred type drifts.
 */
function assertNarrowingScalars(mixed $input): void
{
    \PHPStan\Testing\assertType('string', Assert::string($input));
    \PHPStan\Testing\assertType('non-empty-string', Assert::stringNotEmpty($input));
    \PHPStan\Testing\assertType('int', Assert::integer($input));
    \PHPStan\Testing\assertType('int<1, max>', Assert::positiveInteger($input));
    \PHPStan\Testing\assertType('bool', Assert::boolean($input));
}

function assertNarrowingNotNull(mixed $input): void
{
    $maybe = rand(0, 1) === 1 ? new stdClass() : null;
    \PHPStan\Testing\assertType('stdClass', Assert::notNull($maybe));
    \PHPStan\Testing\assertType('mixed~null', Assert::notNull($input));
}

function assertNotNullReturnContext(?stdClass $maybe): stdClass
{
    return Assert::notNull($maybe);
}

/**
 * @param class-string<stdClass> $class
 */
function assertNarrowingObjects(mixed $input, string $class): void
{
    \PHPStan\Testing\assertType('stdClass', Assert::instanceOf($input, $class));
    \PHPStan\Testing\assertType('list<stdClass>', Assert::allInstanceOf($input, $class));
}

/**
 * @param array{status: string} $row
 */
function assertNarrowingShapes(mixed $input, array $row): void
{
    \PHPStan\Testing\assertType('list<mixed>', Assert::isList($input));
    \PHPStan\Testing\assertType('array<string, mixed>', Assert::isMap($input));
    \PHPStan\Testing\assertType('array<mixed>', Assert::keyExists($row, 'status'));
    \PHPStan\Testing\assertType('float|int', Assert::greaterThan($input, 5));
    \PHPStan\Testing\assertType('float|int', Assert::range($input, 1, 10));
}

/**
 * @param 'active'|'archived' $status
 */
function assertNarrowingGenerics(mixed $input, string $status): void
{
    \PHPStan\Testing\assertType("'active'", Assert::same($input, 'active'));
    \PHPStan\Testing\assertType("'active'|'archived'", Assert::oneOf($status, ['active', 'archived']));
}

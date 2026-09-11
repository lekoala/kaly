<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\InputException;
use Kaly\Http\InputMapper;
use Kaly\Http\RequestInput;
use Kaly\Http\ValidationException;
use Kaly\Tests\Mocks\FullInput;
use Kaly\Tests\Mocks\PaginationInput;
use Kaly\Tests\Mocks\PatientStatus;
use Kaly\Tests\Mocks\Priority;
use Kaly\Tests\Mocks\SaveInput;
use Kaly\Tests\Mocks\UnsupportedInput;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class InputMapperTest extends TestCase
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @param class-string<RequestInput> $class
     */
    private function map(array $query, ?array $body = null, string $class = FullInput::class): RequestInput
    {
        $request = (new ServerRequest('GET', '/'))->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return (new InputMapper())->map($request, $class);
    }

    public function testEveryScalarIsCoercedFromStrings(): void
    {
        $input = $this->map([
            'name' => 'hello',
            'page' => '3',
            'ratio' => '1.25',
            'active' => 'true',
        ]);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame('hello', $input->name);
        $this->assertSame(3, $input->page);
        $this->assertSame(1.25, $input->ratio);
        $this->assertTrue($input->active);
    }

    public function testDefaultsAreLeftToTheConstructor(): void
    {
        $input = $this->map(['name' => 'hello']);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame(1, $input->page);
        $this->assertSame(0.5, $input->ratio);
        $this->assertFalse($input->active);
        $this->assertSame([], $input->tags);
        $this->assertNull($input->status);
    }

    public function testAnEmptyValueIsTreatedAsAbsentExceptForStrings(): void
    {
        // A form posts all of its empty fields
        $input = $this->map(['name' => '', 'page' => '', 'status' => '']);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame('', $input->name);
        $this->assertSame(1, $input->page);
        $this->assertNull($input->status);
    }

    public function testBackedEnumsAreResolved(): void
    {
        $input = $this->map(['name' => 'x', 'status' => 'archived', 'priority' => '2']);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame(PatientStatus::Archived, $input->status);
        $this->assertSame(Priority::High, $input->priority);
    }

    public function testAnUnknownEnumCaseIsABadRequest(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'status' must be");
        $this->map(['name' => 'x', 'status' => 'nonsense']);
    }

    public function testArraysComeFromRepeatedKeys(): void
    {
        $input = $this->map(['name' => 'x', 'tags' => ['a', 'b']]);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame(['a', 'b'], $input->tags);
    }

    public function testAScalarIsNotAnArray(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'tags' must be an array");
        $this->map(['name' => 'x', 'tags' => 'a,b']);
    }

    public function testAnUncoercibleValueIsABadRequest(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'page' must be an integer");
        $this->map(['name' => 'x', 'page' => 'abc']);
    }

    public function testAFloatIsNotAnInteger(): void
    {
        $this->expectException(InputException::class);
        $this->map(['name' => 'x', 'page' => '1.5']);
    }

    public function testARequiredPropertyIsABadRequest(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'name' is required");
        $this->map([]);
    }

    public function testTheBodyCompletesTheQuery(): void
    {
        $input = $this->map(['page' => '2'], ['name' => 'from body']);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame('from body', $input->name);
        $this->assertSame(2, $input->page);
    }

    public function testTheSameValueSentTwiceIsAccepted(): void
    {
        // A client echoing the whole object back is legitimate
        $input = $this->map(['name' => 'x', 'page' => '2'], ['page' => 2]);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame(2, $input->page);
    }

    public function testContradictingValuesAreABadRequest(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'page' was sent in the query and in the body with different values");
        $this->map(['name' => 'x', 'page' => '2'], ['page' => '15']);
    }

    public function testValidationRunsAfterMapping(): void
    {
        $input = $this->map(['page' => '2'], null, PaginationInput::class);
        $this->assertInstanceOf(PaginationInput::class, $input);
        $this->assertSame(2, $input->page);

        // Well typed, still refused: that is a 422, not a 400
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('page must be >= 1');
        $this->map(['page' => '-4'], null, PaginationInput::class);
    }

    public function testValidationExceptionIsUnprocessable(): void
    {
        $this->assertSame(422, (new ValidationException('nope'))->getIntCode());
        $this->assertSame(400, (new InputException('nope'))->getIntCode());
    }

    public function testAnUnsupportedPropertyTypeIsAProgrammingError(): void
    {
        // Not a bad request: it would fail for every single client
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unsupported type');
        $this->map(['when' => '2026-01-01'], null, UnsupportedInput::class);
    }

    public function testBodyOnlyInputWorksWithoutAQueryString(): void
    {
        $input = $this->map([], ['test' => 'body'], SaveInput::class);
        $this->assertInstanceOf(SaveInput::class, $input);
        $this->assertSame('body', $input->test);
    }
}

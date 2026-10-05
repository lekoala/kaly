<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Input\InputException;
use Kaly\Http\Input\InputMapper;
use Kaly\Http\Input\InputResult;
use Kaly\Http\Input\RequestInput;
use Kaly\Http\Input\ValidationException;
use Kaly\Tests\Mocks\FullInput;
use Kaly\Tests\Mocks\OrderStatus;
use Kaly\Tests\Mocks\PaginationInput;
use Kaly\Tests\Mocks\Priority;
use Kaly\Tests\Mocks\SaveInput;
use Kaly\Tests\Mocks\UnsupportedInput;
use Kaly\Validation\ValidationResult;
use Kaly\Validation\Validator;
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
        return $this->mapResult($query, $body, $class)->require();
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @param class-string<RequestInput> $class
     */
    private function mapResult(array $query, ?array $body = null, string $class = FullInput::class): InputResult
    {
        $request = (new ServerRequest('GET', '/'))->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return (new InputMapper())->mapResult($request, $class);
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
        $this->assertSame(OrderStatus::Archived, $input->status);
        $this->assertSame(Priority::High, $input->priority);
    }

    public function testAnUnknownEnumCaseIsABadRequest(): void
    {
        try {
            $this->map(['name' => 'x', 'status' => 'nonsense']);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $this->assertSame(400, $exception->status());
            $violations = $exception->validation()->violations();
            $this->assertCount(1, $violations);
            $this->assertSame('status', $violations[0]->field);
            $this->assertSame('enum', $violations[0]->code);
        }
    }

    public function testArraysComeFromRepeatedKeys(): void
    {
        $input = $this->map(['name' => 'x', 'tags' => ['a', 'b']]);

        $this->assertInstanceOf(FullInput::class, $input);
        $this->assertSame(['a', 'b'], $input->tags);
    }

    public function testAScalarIsNotAnArray(): void
    {
        try {
            $this->map(['name' => 'x', 'tags' => 'a,b']);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $violations = $exception->validation()->violations();
            $this->assertCount(1, $violations);
            $this->assertSame('tags', $violations[0]->field);
            $this->assertSame('type_array', $violations[0]->code);
        }
    }

    public function testAnUncoercibleValueIsABadRequest(): void
    {
        try {
            $this->map(['name' => 'x', 'page' => 'abc']);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $violations = $exception->validation()->violations();
            $this->assertCount(1, $violations);
            $this->assertSame('page', $violations[0]->field);
            $this->assertSame('type_int', $violations[0]->code);
        }
    }

    public function testAFloatIsNotAnInteger(): void
    {
        try {
            $this->map(['name' => 'x', 'page' => '1.5']);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $this->assertSame('type_int', $exception->validation()->violations()[0]->code);
        }
    }

    public function testARequiredPropertyIsABadRequest(): void
    {
        try {
            $this->map([]);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $violations = $exception->validation()->violations();
            $this->assertCount(1, $violations);
            $this->assertSame('name', $violations[0]->field);
            $this->assertSame('required', $violations[0]->code);
            $this->assertSame('input', $violations[0]->domain);
        }
    }

    public function testMappingErrorsAccumulateInsteadOfStoppingAtTheFirst(): void
    {
        $result = $this->mapResult(['page' => 'abc', 'ratio' => 'nonsense']);

        $this->assertFalse($result->isValid());
        $this->assertNull($result->input());
        $this->assertSame(400, $result->status());

        $fields = array_map(static fn($violation) => $violation->field, $result->validation()->violations());
        $this->assertContains('name', $fields);
        $this->assertContains('page', $fields);
        $this->assertContains('ratio', $fields);
    }

    public function testValuesKeepTheSubmittedDataBeforeCoercion(): void
    {
        $result = $this->mapResult(['name' => 'x', 'page' => 'abc']);

        $this->assertSame(['name' => 'x', 'page' => 'abc'], $result->values());
        $this->assertNull($result->input());
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
        $result = $this->mapResult(['name' => 'x', 'page' => '2'], ['page' => '15']);

        $this->assertFalse($result->isValid());
        $this->assertSame(400, $result->status());
        // The query value is kept for re-rendering
        $this->assertSame('2', $result->values()['page']);

        $violations = $result->validation()->violations();
        $this->assertCount(1, $violations);
        $this->assertSame('page', $violations[0]->field);
        $this->assertSame('conflicting_values', $violations[0]->code);
    }

    public function testValidationRunsAfterMapping(): void
    {
        $result = $this->mapResult(['page' => '2'], null, PaginationInput::class);
        $this->assertTrue($result->isValid());
        $this->assertNull($result->status());
        $this->assertInstanceOf(PaginationInput::class, $result->input());

        // Well typed, still refused: that is a 422, not a 400
        $result = $this->mapResult(['page' => '-4'], null, PaginationInput::class);
        $this->assertFalse($result->isValid());
        $this->assertSame(422, $result->status());
        $this->assertInstanceOf(PaginationInput::class, $result->input());

        $violations = $result->validation()->violations();
        $this->assertCount(1, $violations);
        $this->assertSame('page', $violations[0]->field);
        $this->assertSame('between', $violations[0]->code);
    }

    public function testRequireThrowsTheDocumentedStatus(): void
    {
        try {
            $this->map(['page' => '-4'], null, PaginationInput::class);
            $this->fail('mapping should have failed');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status());
        }

        try {
            $this->map([]);
            $this->fail('mapping should have failed');
        } catch (InputException $exception) {
            $this->assertSame(400, $exception->status());
        }
    }

    public function testValidationIsSkippedWhenMappingFails(): void
    {
        // page is not an integer so no DTO exists: the between rule never runs
        $result = $this->mapResult(['page' => 'abc'], null, PaginationInput::class);

        $this->assertNull($result->input());
        $this->assertSame(400, $result->status());
        $this->assertSame(['type_int'], array_map(static fn($violation) => $violation->code, $result->validation()->violations()));
    }

    public function testExceptionsExposeTheFirstMessageAsTheirBody(): void
    {
        $validator = new Validator();
        $validator->notBlank('name', '');

        $this->assertSame('This value must not be blank', (new ValidationException($validator->result()))->getResponseBody());
        $this->assertSame(422, (new ValidationException(new ValidationResult()))->status());
        $this->assertSame('', (new ValidationException(new ValidationResult()))->getResponseBody());
        $this->assertSame(400, (new InputException(new ValidationResult()))->status());
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

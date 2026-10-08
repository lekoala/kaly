<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Kaly\Ex;
use Kaly\Http\Exception\ForbiddenException;
use Kaly\Http\Exception\HttpException;
use Kaly\Http\Exception\HttpExceptionInterface;
use Kaly\Http\Exception\MethodNotAllowedException;
use Kaly\Http\Exception\NotFoundException;
use Kaly\Http\Exception\RedirectException;
use Kaly\Http\Exception\TooManyRequestsException;
use Kaly\Http\Input\InputException;
use Kaly\Http\Input\ValidationException;
use Kaly\Router\RouteNotFoundException;
use Kaly\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Every client-facing failure is one HttpException, and a non-HTTP one is not.
 */
class HttpExceptionTest extends TestCase
{
    private function invalidInput(): ValidationException
    {
        $validator = new Validator();
        $validator->notBlank('name', '');
        return new ValidationException($validator->result());
    }

    private function unmappedInput(): InputException
    {
        $validator = new Validator();
        $validator->notBlank('name', '');
        return new InputException($validator->result());
    }

    public function testEveryHttpFailureSharesTheBase(): void
    {
        $exceptions = [
            new NotFoundException(),
            new RouteNotFoundException(),
            new ForbiddenException(),
            new MethodNotAllowedException(['GET']),
            new TooManyRequestsException(),
            $this->unmappedInput(),
            $this->invalidInput(),
            new RedirectException('/x'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(HttpException::class, $exception, $exception::class);
            $this->assertInstanceOf(HttpExceptionInterface::class, $exception, $exception::class);
            // The status travels with the exception, not through Exception::$code
            $this->assertSame($exception->status(), (int) $exception->getCode(), $exception::class);
        }
    }

    public function testStatusesAreTheDocumentedOnes(): void
    {
        $this->assertSame(404, (new NotFoundException())->status());
        $this->assertSame(403, (new ForbiddenException())->status());
        $this->assertSame(405, (new MethodNotAllowedException())->status());
        $this->assertSame(429, (new TooManyRequestsException())->status());
        $this->assertSame(400, $this->unmappedInput()->status());
        $this->assertSame(422, $this->invalidInput()->status());
        $this->assertSame(307, (new RedirectException('/x'))->status());
    }

    public function testTheStatusIsOverridable(): void
    {
        $this->assertSame(418, (new NotFoundException('teapot', 418))->status());
        $this->assertSame(401, (new ForbiddenException('token', 401))->status());
    }

    /**
     * A 404 and a 403 must not tell the client anything; the rest answer with
     * their message, which is the user's own feedback.
     */
    public function testOnlyTheSilentOnesHideTheirBody(): void
    {
        $this->assertSame('', (new NotFoundException())->getResponseBody());
        $this->assertSame('', (new ForbiddenException())->getResponseBody());
        $this->assertSame('', (new MethodNotAllowedException(['GET']))->getResponseBody());
        $this->assertSame('', (new TooManyRequestsException(30))->getResponseBody());

        $this->assertSame('This value must not be blank', $this->unmappedInput()->getResponseBody());
        $this->assertSame('This value must not be blank', $this->invalidInput()->getResponseBody());
    }

    public function testHeadersArePartOfTheException(): void
    {
        $this->assertSame(['Allow' => 'GET, POST'], (new MethodNotAllowedException(['GET', 'POST', 'GET']))->getResponseHeaders());
        $this->assertSame([], (new MethodNotAllowedException([]))->getResponseHeaders());
        $this->assertSame(['Location' => '/target'], (new RedirectException('/target'))->getResponseHeaders());
        $this->assertSame([], (new TooManyRequestsException())->getResponseHeaders());
        $this->assertSame(['Retry-After' => '30'], (new TooManyRequestsException(30))->getResponseHeaders());
        $this->assertSame(
            ['Retry-After' => 'Fri, 02 Jan 2026 03:04:05 GMT'],
            (new TooManyRequestsException(new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'))))->getResponseHeaders(),
        );
    }

    /**
     * A non-HTTP failure is a plain Ex: it carries no status for the handler
     * to map, which is what keeps a 500 from being built out of a stray code.
     */
    public function testANonHttpFailureCarriesNoStatus(): void
    {
        $ex = new Ex('Module not found');

        $this->assertNotInstanceOf(HttpExceptionInterface::class, $ex);
        $this->assertFalse(method_exists($ex, 'status'));
        $this->assertSame(0, (int) $ex->getCode());
    }

    public function testInputAndValidationAreSiblingsUnderTheSameParent(): void
    {
        // They are genuinely different failures (400 vs 422) but a handler
        // that wants "the request was refused" catches the family
        $this->assertInstanceOf(HttpException::class, $this->invalidInput());
        $this->assertNotInstanceOf(InputException::class, $this->invalidInput());
        $this->assertNotInstanceOf(ValidationException::class, $this->unmappedInput());
    }
}

<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Clock\FrozenClock;
use Kaly\Http\ConditionalRequest;
use Kaly\Http\FileResponseFactory;
use Kaly\Util\Dates;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;

class ConditionalRequestTest extends TestCase
{
    private FrozenClock $clock;
    private ConditionalRequest $conditional;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(Dates::instant('2026-10-09T12:00:00Z'));
        $this->conditional = new ConditionalRequest($this->clock);
    }

    /** @return iterable<string,array{string,?string,bool}> */
    public static function etags(): iterable
    {
        yield 'strong' => ['"abc"', '"abc"', true];
        yield 'weak client' => ['W/"abc"', '"abc"', true];
        yield 'weak server' => ['"abc"', 'W/"abc"', true];
        yield 'both weak' => ['W/"abc"', 'W/"abc"', true];
        yield 'different' => ['"def"', '"abc"', false];
        yield 'case sensitive' => ['"ABC"', '"abc"', false];
        yield 'list' => ['"abc", W/"def"', '"def"', true];
        yield 'whitespace' => [" \t\"abc\" ,\t W/\"def\"\t ", '"def"', true];
        yield 'comma in tag' => ['"a,b"', 'W/"a,b"', true];
        yield 'comma is not separator in tag' => ['"a,b"', '"a"', false];
        yield 'backslash is opaque' => ['"a\\b"', '"a\\b"', true];
        yield 'empty opaque tag' => ['""', 'W/""', true];
        yield 'obs text' => ["\"\x80\"", "W/\"\x80\"", true];
        yield 'empty list members' => [', , "abc",,', '"abc"', true];
        yield 'wildcard' => ['*', '"abc"', true];
        yield 'wildcard without validator' => ['*', null, true];
        yield 'mixed wildcard first' => ['*, "abc"', '"abc"', false];
        yield 'mixed wildcard last' => ['"abc", *', '"abc"', false];
        yield 'duplicate wildcard' => ['*, *', null, false];
        yield 'unquoted request' => ['abc', '"abc"', false];
        yield 'unquoted supplied tag' => ['"abc"', 'abc', false];
        yield 'malformed suffix after match' => ['"abc", invalid', '"abc"', false];
        yield 'missing separator' => ['"abc" "def"', '"abc"', false];
        yield 'unterminated' => ['"abc', '"abc"', false];
        yield 'space inside tag' => ['"a b"', '"a b"', false];
        yield 'lowercase weak prefix' => ['w/"abc"', '"abc"', false];
        yield 'control byte in supplied tag' => ['"abc"', "\"abc\x7F\"", false];
        yield 'empty header' => ['', '"abc"', false];
        yield 'no supplied validator' => ['"abc"', null, false];
    }

    #[DataProvider('etags')]
    public function testEtagRevalidation(string $value, ?string $etag, bool $expected): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $request = new Request($method, '/', [
                'If-None-Match' => $value,
                'If-Modified-Since' => 'Sun, 06 Nov 1994 08:49:37 GMT',
            ]);
            $this->assertSame($expected, $this->conditional->isNotModified($request, $etag, Dates::instant('1994-11-06T08:49:37Z')));
        }
    }

    public function testMultipleEtagHeaderLinesAreAList(): void
    {
        $request = new Request('GET', '/', ['If-None-Match' => ['"other"', 'W/"abc"']]);
        $this->assertTrue($this->conditional->isNotModified($request, '"abc"'));
        $this->assertFalse($this->conditional->isNotModified($request->withAddedHeader('If-None-Match', '*'), '"abc"'));
    }

    /** @return iterable<string,array{string,bool}> */
    public static function dates(): iterable
    {
        yield 'IMF fixdate' => ['Sun, 06 Nov 1994 08:49:37 GMT', true];
        yield 'RFC 850' => ['Sunday, 06-Nov-94 08:49:37 GMT', true];
        yield 'asctime' => ['Sun Nov  6 08:49:37 1994', true];
        yield 'later' => ['Sun, 06 Nov 1994 08:49:38 GMT', true];
        yield 'earlier' => ['Sun, 06 Nov 1994 08:49:36 GMT', false];
        yield 'invalid calendar' => ['Thu, 31 Feb 1994 08:49:37 GMT', false];
        yield 'invalid RFC 850 calendar' => ['Thursday, 31-Feb-94 08:49:37 GMT', false];
        yield 'invalid asctime calendar' => ['Thu Feb 31 08:49:37 1994', false];
        yield 'invalid leap day' => ['Mon, 29 Feb 1993 08:49:37 GMT', false];
        yield 'invalid zero year' => ['Sat, 06 Nov 0000 08:49:37 GMT', false];
        yield 'invalid weekday' => ['Mon, 06 Nov 1994 08:49:37 GMT', false];
        yield 'invalid hour' => ['Sun, 06 Nov 1994 24:49:37 GMT', false];
        yield 'invalid minute' => ['Sun, 06 Nov 1994 08:60:37 GMT', false];
        yield 'invalid second' => ['Sun, 06 Nov 1994 08:49:61 GMT', false];
        yield 'wrong timezone' => ['Sun, 06 Nov 1994 08:49:37 UTC', false];
        yield 'relative' => ['tomorrow', false];
        yield 'multiple dates' => ['Sun, 06 Nov 1994 08:49:37 GMT, Sun, 06 Nov 1994 08:49:38 GMT', false];
        yield 'trailing garbage' => ['Sun, 06 Nov 1994 08:49:37 GMT garbage', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('dates')]
    public function testDateRevalidation(string $value, bool $expected): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $request = new Request($method, '/', ['If-Modified-Since' => $value]);
            $this->assertSame($expected, $this->conditional->isNotModified(
                $request,
                lastModified: Dates::instant('1994-11-06T08:49:37.999999Z'),
            ));
        }
    }

    public function testDateComparisonUsesInstantsRegardlessOfTimezone(): void
    {
        $request = new Request('GET', '/', ['If-Modified-Since' => 'Sun, 06 Nov 1994 08:49:37 GMT']);
        $this->assertTrue($this->conditional->isNotModified($request, lastModified: Dates::instant('1994-11-06T09:49:37+01:00')));
        $this->assertFalse($this->conditional->isNotModified($request));
        $this->assertFalse($this->conditional->isNotModified(new Request('GET', '/'), '"abc"', Dates::instant('1994-11-06T08:49:37Z')));
        $this->assertFalse($this->conditional->isNotModified(
            $request->withAddedHeader('If-Modified-Since', 'Sun, 06 Nov 1994 08:49:37 GMT'),
            lastModified: Dates::instant('1994-11-06T08:49:37Z'),
        ));
    }

    public function testRfc850UsesTheInjectedClockAtTheFiftyYearBoundary(): void
    {
        $lastModified = Dates::instant('2076-10-09T12:00:00Z');
        $request = new Request('GET', '/', ['If-Modified-Since' => 'Friday, 09-Oct-76 12:00:00 GMT']);
        $this->assertTrue($this->conditional->isNotModified($request, lastModified: $lastModified));
        $this->clock->setTo(Dates::instant('2026-10-09T11:59:59Z'));
        $this->assertFalse($this->conditional->isNotModified($request, lastModified: $lastModified));
        // After rolling back a century, the weekday must describe the past date.
        $request = $request->withHeader('If-Modified-Since', 'Saturday, 09-Oct-76 12:00:00 GMT');
        $this->assertTrue($this->conditional->isNotModified($request, lastModified: Dates::instant('1976-10-09T12:00:00Z')));
    }

    public function testLeapSecondIsAcceptedWithoutNormalizingInvalidCalendarDates(): void
    {
        $request = new Request('GET', '/', ['If-Modified-Since' => 'Sat, 31 Dec 2016 23:59:60 GMT']);
        $this->assertTrue($this->conditional->isNotModified($request, lastModified: Dates::instant('2017-01-01T00:00:00Z')));
        $this->assertFalse($this->conditional->isNotModified(
            $request->withHeader('If-Modified-Since', 'Sun, 32 Dec 2016 23:59:60 GMT'),
            lastModified: Dates::instant('2017-01-01T00:00:00Z'),
        ));
    }

    public function testOtherMethodsAndHigherPriorityPreconditionsCannotProduce304(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'OPTIONS', 'get'] as $method) {
            $this->assertFalse($this->conditional->isNotModified(new Request($method, '/', ['If-None-Match' => '*'])));
        }
        foreach (['If-Match', 'If-Unmodified-Since'] as $header) {
            $request = new Request('GET', '/', ['If-None-Match' => '*', $header => '']);
            $this->assertFalse($this->conditional->isNotModified($request));
            $this->assertTrue($this->conditional->isNotModified($request->withoutHeader($header)));
        }
    }

    public function testApplicationCanReturn304BeforeOpeningAFileAndRetainCacheHeaders(): void
    {
        $responses = new Psr17Factory();
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams->expects($this->never())->method('createStreamFromFile');
        $files = new FileResponseFactory($responses, $streams);
        $headers = [
            'ETag' => '"abc"',
            'Last-Modified' => 'Sun, 06 Nov 1994 08:49:37 GMT',
            'Date' => 'Fri, 09 Oct 2026 12:00:00 GMT',
            'Vary' => 'Accept',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Expires' => 'Fri, 09 Oct 2026 12:00:00 GMT',
            'Content-Location' => '/document',
        ];
        foreach (['GET', 'HEAD'] as $method) {
            $request = new Request($method, '/document', ['If-None-Match' => 'W/"abc"']);
            // The application has already resolved the resource and checked access.
            $response = $this->conditional->isNotModified($request, $headers['ETag'])
                ? $responses->createResponse(304)
                : $files->create(__FILE__, method: $request->getMethod());
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            $this->assertSame(304, $response->getStatusCode());
            $this->assertSame('', (string) $response->getBody());
            foreach ($headers as $name => $value) {
                $this->assertSame($value, $response->getHeaderLine($name));
            }
        }
    }
}

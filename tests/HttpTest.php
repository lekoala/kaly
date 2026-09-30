<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http;
use Kaly\Http\Accept;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\RequestUtils;
use Kaly\Http\ResponseEmitter;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpTest extends TestCase
{
    public static array $mockResponse = [];

    public function testParseLanguage(): void
    {
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept-Language', 'en-US,en;q=0.9,fr;q=0.8');

        $result = RequestUtils::parseAcceptedLanguages($request);
        $this->assertArrayHasKey('en-US', $result);
        $this->assertArrayHasKey('en', $result);
        $this->assertArrayHasKey('fr', $result);
        $this->assertEquals(0.8, $result['fr']);

        $preferred = RequestUtils::getPreferredLanguage($request);
        $this->assertEquals('en-US', $preferred);

        $preferred = RequestUtils::getPreferredLanguage($request, ['en', 'fr']);
        $this->assertEquals('en', $preferred);
    }

    public function testParseAccept(): void
    {
        $v = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.9';
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', $v);

        $result = RequestUtils::parseAcceptHeader($request);
        $this->assertContains('text/html', $result);
        $this->assertContains('image/webp', $result);

        $preferred = RequestUtils::getPreferredContentType($request);
        $this->assertEquals('text/html', $preferred);
    }

    public function testCreateRequest(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testCreateResponse(): void
    {
        $response = HttpFactory::createResponse();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testResponseFactory(): void
    {
        $factory = HttpFactory::get();
        $this->assertInstanceOf(ResponseInterface::class, $factory->createResponse());
    }

    public function testSendResponse(): void
    {
        $headers = [];
        $code = 200;
        $body = 'Test body';
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString($body);

        $emitter = new ResponseEmitter();
        $emitter->emit($testResponse);
    }

    public function testChunkedSendResponse(): void
    {
        $headers = [];
        $code = 200;
        $body = 'Test body';
        $testResponse = new Response($code, $headers, $body);

        // It should rewind properly
        $testResponse->getBody()->seek(2);

        $this->expectOutputString($body);

        $emitter = new ResponseEmitter(1);
        $emitter->emit($testResponse);
    }

    public function testMethodChecks(): void
    {
        $get = new BaseServerRequest('GET', '/');
        $this->assertTrue(RequestUtils::isGet($get));
        $this->assertTrue(RequestUtils::isMethod($get, 'GET'));
        $this->assertFalse(RequestUtils::isPost($get));
        $this->assertFalse(RequestUtils::isPut($get));
        $this->assertFalse(RequestUtils::isPatch($get));
        $this->assertFalse(RequestUtils::isDelete($get));
        $this->assertFalse(RequestUtils::isHead($get));
        $this->assertFalse(RequestUtils::isOptions($get));

        $post = new BaseServerRequest('POST', '/');
        $this->assertTrue(RequestUtils::isPost($post));
        $this->assertFalse(RequestUtils::isGet($post));
    }

    public function testIsXhr(): void
    {
        $plain = new BaseServerRequest('GET', '/');
        $this->assertFalse(RequestUtils::isXhr($plain));

        // fetch() does not send this header by default, only XHR libraries do
        $xhr = (new BaseServerRequest('GET', '/'))->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->assertTrue(RequestUtils::isXhr($xhr));
    }

    public function testParamAccessors(): void
    {
        $request = (new BaseServerRequest('GET', '/'))->withQueryParams(['page' => '2'])->withParsedBody(['name' => 'AUDIT']);

        $this->assertSame('2', RequestUtils::getQueryParam($request, 'page'));
        $this->assertSame('fallback', RequestUtils::getQueryParam($request, 'missing', 'fallback'));
        $this->assertSame('AUDIT', RequestUtils::getParsedBodyParam($request, 'name'));
        $this->assertSame('fallback', RequestUtils::getParsedBodyParam($request, 'missing', 'fallback'));
        // Body wins over query, like a regular form post
        $this->assertSame('AUDIT', RequestUtils::getRequestParam($request, 'name'));
        $this->assertSame('2', RequestUtils::getRequestParam($request, 'page'));

        $objectBody = (new BaseServerRequest('POST', '/'))->withParsedBody((object) ['name' => 'OBJECT']);
        $this->assertSame('OBJECT', RequestUtils::getParsedBodyParam($objectBody, 'name'));
        $this->assertSame('OBJECT', RequestUtils::getRequestParam($objectBody, 'name'));
    }

    public function testServerParamFallsBackOnNonString(): void
    {
        $request = new BaseServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '1.2.3.4']);
        $this->assertSame('1.2.3.4', RequestUtils::getServerParam($request, 'REMOTE_ADDR'));
        $this->assertSame('fallback', RequestUtils::getServerParam($request, 'MISSING', 'fallback'));

        $odd = new BaseServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => ['1.2.3.4']]);
        $this->assertNull(RequestUtils::getServerParam($odd, 'REMOTE_ADDR'));
    }

    public function testGetIpFallsBack(): void
    {
        $request = new BaseServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '1.2.3.4']);
        $this->assertSame('1.2.3.4', RequestUtils::getIp($request));

        $missing = new BaseServerRequest('GET', '/');
        $this->assertSame('0.0.0.0', RequestUtils::getIp($missing));

        $blank = new BaseServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '']);
        $this->assertSame('0.0.0.0', RequestUtils::getIp($blank));
    }

    public function testAcceptWeightsDecideTheBestType(): void
    {
        // Best first: the weights sort the entries, whatever the client order
        $weighted = Accept::parse('text/plain;q=1.0, application/json;q=0.9');
        $this->assertSame(['text/plain', 'application/json'], $weighted->toArray());

        $best = Accept::parse('text/html;q=0.5, application/json');
        $this->assertSame(['application/json', 'text/html'], $best->toArray());

        // The priority list is the server preference, filtered by what the
        // client accepts: html is refused here, so json answers either way
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', 'text/html;q=0.5, application/json');
        $this->assertSame('application/json', RequestUtils::getPreferredContentType($request, ['application/json', 'text/html']));
        $this->assertSame('application/json', RequestUtils::getPreferredContentType($request, ['text/html', 'application/json']));
    }

    public function testTheServerPriorityBreaksATie(): void
    {
        // Same weight: the server order decides
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', 'text/html, application/json');
        $this->assertSame('application/json', RequestUtils::getPreferredContentType($request, ['application/json', 'text/html']));
        $this->assertSame('text/html', RequestUtils::getPreferredContentType($request, ['text/html', 'application/json']));
    }

    public function testAcceptWildcardsOnlyFillTheGaps(): void
    {
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', 'text/html;q=0.5, */*;q=0.9');
        $accept = Accept::fromRequest($request);

        // The explicit entry is the client's real opinion for text/html
        $this->assertSame(0.5, $accept->qualityFor('text', 'html'));
        // The wildcard is all the client said about application/json
        $this->assertSame(0.9, $accept->qualityFor('application', 'json'));
        // ... but it expresses no opinion, so it is not an explicit acceptance
        $this->assertSame(0.0, $accept->explicitQualityFor('application', 'json'));
        $this->assertSame(0.5, $accept->explicitQualityFor('text', 'html'));
    }

    public function testAcceptReadsTheSameHeaderForEveryCaller(): void
    {
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', 'text/html;q=0.5, application/json');
        $accept = Accept::fromRequest($request);

        // The negotiator and the legacy list agree, best first
        $this->assertSame(['application/json', 'text/html'], $accept->toArray());
        $this->assertSame($accept->toArray(), RequestUtils::parseAcceptHeader($request));
        // and both see the weights
        $this->assertTrue(ExceptionHandler::wantsJson($request));
    }

    public function testNegotiationFallsBackWhenTheClientRefusesEverything(): void
    {
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept', 'application/xml');

        // Nothing in the list is acceptable, so the server preference answers
        $this->assertSame('text/html', RequestUtils::getPreferredContentType($request, ['text/html']));
        // A client that sent no preference is served plain text
        $this->assertSame('text/plain', RequestUtils::getPreferredContentType(new BaseServerRequest('GET', '/')));
    }

    public function testMediaTypeParamsSurviveOddHeaders(): void
    {
        // A parameter without a value used to read past the end of the part
        $valueless = (new BaseServerRequest('GET', '/'))->withHeader('Content-Type', 'text/html;charset');
        $this->assertSame(['charset' => ''], RequestUtils::getMediaTypeParams($valueless));
        $this->assertSame('text/html', RequestUtils::getMediaType($valueless));

        // A quoted value keeps the separators it contains
        $boundary = (new BaseServerRequest('GET', '/'))->withHeader('Content-Type', 'multipart/form-data; boundary="a;b"');
        $this->assertSame('a;b', RequestUtils::getMediaTypeParams($boundary)['boundary']);

        $charset = (new BaseServerRequest('GET', '/'))->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertSame('UTF-8', RequestUtils::getContentCharset($charset));

        $this->assertSame([], RequestUtils::getMediaTypeParams(new BaseServerRequest('GET', '/')));
        $this->assertNull(RequestUtils::getMediaType(new BaseServerRequest('GET', '/')));
    }

    public function testAcceptParameterValuesAreQuoteAware(): void
    {
        // A quoted parameter containing a comma stays within one entry, and a
        // spaced or quoted q weight is still read
        $accept = Accept::parse('application/json; note="a,b"; q = 0.8, text/html');
        $this->assertSame(0.8, $accept->qualityFor('application', 'json'));
        $this->assertSame(1.0, $accept->qualityFor('text', 'html'));

        $quoted = Accept::parse('application/json;q="0.5"');
        $this->assertSame(0.5, $quoted->qualityFor('application', 'json'));
    }

    public function testContentRangeSendResponse(): void
    {
        $headers = [
            'Content-Range' => 'bytes 0-3/8',
        ];
        $code = 200;
        $body = 'Test body';
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString('Test');

        $emitter = new ResponseEmitter();
        $emitter->emit($testResponse);
    }
}

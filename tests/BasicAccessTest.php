<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Auth\Middleware\BasicAccessMiddleware;
use Kaly\Http\Exception\UnauthorizedException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class BasicAccessTest extends TestCase
{
    public function testEmptyCredentialsFailFast(): void
    {
        try {
            new BasicAccessMiddleware('', 's3cret');
            $this->fail('Empty username should have thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Basic access username must not be empty', $e->getMessage());
        }

        try {
            new BasicAccessMiddleware('stage', '');
            $this->fail('Empty password should have thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Basic access password must not be empty', $e->getMessage());
        }
    }

    public function testValidCredentialsPassAndOthersChallenge(): void
    {
        $middleware = new BasicAccessMiddleware('stage', 's3cret');

        $response = $middleware->process($this->request('stage:s3cret'), $this->next());
        $this->assertSame(200, $response->getStatusCode());

        try {
            $middleware->process($this->request('stage:wrong'), $this->next());
            $this->fail('Wrong password should have challenged');
        } catch (UnauthorizedException $e) {
            $this->assertSame(401, $e->status());
            $this->assertSame('Basic realm="Staging"', $e->getResponseHeaders()['WWW-Authenticate']);
        }

        try {
            $middleware->process(new ServerRequest('GET', '/'), $this->next());
            $this->fail('Missing credentials should have challenged');
        } catch (UnauthorizedException $e) {
            $this->assertSame(401, $e->status());
        }
    }

    public function testRealmIsEscapedAsAQuotedString(): void
    {
        $middleware = new BasicAccessMiddleware('stage', 's3cret', 'a"b\\c');

        try {
            $middleware->process(new ServerRequest('GET', '/'), $this->next());
            $this->fail('Missing credentials should have challenged');
        } catch (UnauthorizedException $e) {
            $this->assertSame('Basic realm="a\\"b\\\\c"', $e->getResponseHeaders()['WWW-Authenticate']);
        }
    }

    public function testRealmWithHeaderBreakIsRefusedByThePsr7Response(): void
    {
        $middleware = new BasicAccessMiddleware('stage', 's3cret', "a\r\nB: evil");

        try {
            $middleware->process(new ServerRequest('GET', '/'), $this->next());
            $this->fail('Missing credentials should have challenged');
        } catch (UnauthorizedException $e) {
            $challenge = $e->getResponseHeaders()['WWW-Authenticate'];
            $this->expectException(InvalidArgumentException::class);
            (new Response())->withHeader('WWW-Authenticate', $challenge);
        }
    }

    private function request(string $credentials): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/'))->withHeader('Authorization', 'Basic ' . base64_encode($credentials));
    }

    private function next(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}

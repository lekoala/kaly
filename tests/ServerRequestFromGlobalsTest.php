<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Psr17Discovery;
use Kaly\Http\ServerRequestFromGlobals;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

class ServerRequestFromGlobalsTest extends TestCase
{
    private function factory(): ServerRequestFromGlobals
    {
        $psr17 = new Psr17Factory();
        return new ServerRequestFromGlobals($psr17, $psr17, $psr17, $psr17);
    }

    public function testDiscoveryFindsTheInstalledImplementation(): void
    {
        $found = Psr17Discovery::find();

        $this->assertCount(6, $found);
        $this->assertSame(Psr17Factory::class, $found[ResponseFactoryInterface::class]);
    }

    public function testBuildsTheRequestFromTheServer(): void
    {
        $request = $this->factory()->create(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/shop/cart/?page=2',
                'HTTP_HOST' => 'example.test:8080',
                'HTTPS' => 'on',
                'SERVER_PROTOCOL' => 'HTTP/2',
                'HTTP_ACCEPT_LANGUAGE' => 'fr',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'PHP_AUTH_USER' => 'jane',
                'PHP_AUTH_PW' => 'secret',
            ],
            query: ['page' => '2'],
            post: ['qty' => '3'],
            cookies: ['theme' => 'dark'],
            files: [],
        );

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.test:8080/shop/cart/?page=2', (string) $request->getUri());
        $this->assertSame('2', $request->getProtocolVersion());
        $this->assertSame('fr', $request->getHeaderLine('Accept-Language'));
        $this->assertSame('Basic ' . base64_encode('jane:secret'), $request->getHeaderLine('Authorization'));
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['qty' => '3'], $request->getParsedBody());
        $this->assertSame(['theme' => 'dark'], $request->getCookieParams());
    }

    public function testOnlyFormPostsHaveAParsedBody(): void
    {
        $request = $this->factory()->create(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/json'],
            query: [],
            post: ['ignored' => '1'],
            cookies: [],
            files: [],
        );

        $this->assertNull($request->getParsedBody());
    }

    public function testNormalizesUploadedFiles(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kaly');
        file_put_contents((string) $tmp, 'content');

        $request = $this->factory()->create(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'],
            query: [],
            post: [],
            cookies: [],
            files: [
                'avatar' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 7],
                'docs' => [
                    'name' => ['x.pdf', 'y.pdf'],
                    'type' => ['application/pdf', 'application/pdf'],
                    'tmp_name' => [$tmp, ''],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
                    'size' => [7, 0],
                ],
            ],
        );
        unlink((string) $tmp);

        $files = $request->getUploadedFiles();
        $this->assertInstanceOf(UploadedFileInterface::class, $files['avatar']);
        $this->assertSame('a.png', $files['avatar']->getClientFilename());
        $this->assertSame('content', (string) $files['avatar']->getStream());
        $this->assertIsArray($files['docs']);
        $this->assertCount(2, $files['docs']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $files['docs'][1]->getError());
    }

    public function testDefaultPortsAreNotRepeated(): void
    {
        $request = $this->factory()->create(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/a',
                'SERVER_NAME' => 'example.test',
                'SERVER_PORT' => '80',
                'QUERY_STRING' => '',
            ],
            query: [],
            post: [],
            cookies: [],
            files: [],
        );

        $this->assertSame('http://example.test/a', (string) $request->getUri());
    }
}

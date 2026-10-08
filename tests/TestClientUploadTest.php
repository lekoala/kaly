<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Test\TestClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestClientUploadTest extends TestCase
{
    private UploadProbeMiddleware $probe;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->probe = new UploadProbeMiddleware();
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true);
        $app->middleware()->incoming($this->probe);
        $this->client = TestClient::for($app->boot());
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testNestedAndMultipleUploadsPreserveMetadataErrorsAndContents(): void
    {
        $image = new UploadedFile(Stream::create('photo'), 5, UPLOAD_ERR_OK, 'photo.jpg', 'image/jpeg');
        $missing = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);
        $files = ['image' => $image, 'gallery' => ['photos' => [$image, $missing]]];

        $this->client->post('/media', ['form' => ['title' => 'Photo'], 'files' => $files])->assertStatus(200);
        $request = $this->probe->requests[0];

        $this->assertSame(['title' => 'Photo'], $request->getParsedBody());
        $this->assertSame($files, $request->getUploadedFiles());
        $this->assertSame('photo', (string) $image->getStream());
        $this->assertSame('photo.jpg', $image->getClientFilename());
        $this->assertSame('image/jpeg', $image->getClientMediaType());
        $this->assertSame(UPLOAD_ERR_NO_FILE, $missing->getError());
    }

    public function testFilesCanBeSentWithoutForm(): void
    {
        $files = ['image' => new UploadedFile(Stream::create('photo'), 5, UPLOAD_ERR_OK)];
        $this->client->post('/media', ['files' => $files])->assertStatus(200);

        $this->assertSame([], $this->probe->requests[0]->getParsedBody());
        $this->assertSame($files, $this->probe->requests[0]->getUploadedFiles());
        $this->client->get('/media');
        $this->assertSame([], $this->probe->requests[1]->getUploadedFiles());
    }

    public function testInvalidNestedFileFailsBeforeSending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Every file must be an UploadedFileInterface');
        $this->client->post('/media', ['files' => ['gallery' => ['bad' => 'photo.jpg']]]);
    }

    #[DataProvider('incompatibleBodies')]
    public function testFilesRejectOtherBodyKinds(string $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('files may only be combined with form');
        $options = $kind === 'json' ? ['files' => [], 'json' => 'content'] : ['files' => [], 'body' => 'content'];
        $this->client->post('/media', $options);
    }

    /** @return list<array{string}> */
    public static function incompatibleBodies(): array
    {
        return [['json'], ['body']];
    }

    #[DataProvider('redirects')]
    public function testRedirectsPreserveUploadsOnlyWhenReplayingPost(int $status, string $method): void
    {
        $this->probe->redirectStatus = $status;
        $files = ['image' => new UploadedFile(Stream::create('photo'), 5, UPLOAD_ERR_OK)];
        $this->client->post('/start', ['form' => ['title' => 'Photo'], 'files' => $files, 'maxRedirects' => 1]);
        $request = $this->probe->requests[1];

        $this->assertSame($method, $request->getMethod());
        $this->assertSame($method === 'POST' ? $files : [], $request->getUploadedFiles());
        $this->assertSame($method === 'POST' ? ['title' => 'Photo'] : null, $request->getParsedBody());
    }

    /** @return list<array{int,string}> */
    public static function redirects(): array
    {
        return [[301, 'GET'], [302, 'GET'], [303, 'GET'], [307, 'POST'], [308, 'POST']];
    }
}

final class UploadProbeMiddleware implements MiddlewareInterface
{
    /** @var list<ServerRequestInterface> */
    public array $requests = [];

    public int $redirectStatus = 307;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->requests[] = $request;
        return $request->getUri()->getPath() === '/start' ? new Response($this->redirectStatus, ['Location' => '/media']) : new Response();
    }
}

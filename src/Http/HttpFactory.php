<?php

declare(strict_types=1);

namespace Kaly\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use InvalidArgumentException;
use Stringable;
use Psr\Http\Message\MessageInterface;

/**
 * This static factory allows creating request/responses without the DI Container
 */
class HttpFactory
{
    public static function get(): Psr17Factory
    {
        return new Psr17Factory();
    }

    public static function sendResponse(ResponseInterface $response): void
    {
        $emitter = new ResponseEmitter();
        $emitter->emit($response);
    }

    public static function createRequestFromGlobals(): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();

        $creator = new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );

        return $creator->fromGlobals();
    }

    /**
     * @param string $body
     * @param int $code
     * @param array<string,string> $headers
     * @return ResponseInterface
     */
    public static function createResponse(string $body = "", int $code = 200, array $headers = []): ResponseInterface
    {
        return new Response($code, $headers, $body);
    }

    /**
     * @param int $code
     * @param array<string,string> $headers
     * @return ResponseInterface
     */
    public static function createErrorResponse(int $code = 400, array $headers = []): ResponseInterface
    {
        return new Response($code, $headers, null);
    }

    public static function createRedirectResponse(string $url, int $code = 307, string $body = ''): ResponseInterface
    {
        if ($code < 300 || $code > 399) {
            throw new InvalidArgumentException("$code should be between 300 and 399");
        }
        $headers = [];
        $headers['Location'] = $url;
        if (!$body) {
            $body = 'You are being redirected to ' . $url;
        }
        return self::createResponse($body, $code, $headers);
    }

    /**
     * Create a lightweight json response that minimize data transfer without adding
     * unnecessary stuff
     *
     * Strings are stored under the "message" key
     *
     * Clients are expected to check http status code, not response body
     *
     * @param string|Stringable|MessageInterface|array<mixed>|null $data
     * @param integer $code
     * @param array<string,string> $headers
     */
    public static function createJsonResponse(
        string|Stringable|MessageInterface|array|null $data,
        int $code = 200,
        array $headers = []
    ): ResponseInterface {
        if (is_object($data)) {
            $data = self::getBodyFromObject($data);
        }
        if (!$data) {
            $data = [];
        }
        if (is_string($data)) {
            $data = ["message" => $data];
        }
        $body = json_encode($data);
        // We couldn't encode the array, output errors
        if (!$body) {
            $code = 500;
            $body = '{"message":"' . json_last_error_msg() . '"}';
        }
        $headers['Content-Type'] = ContentType::JSON;
        return self::createResponse($body, $code, $headers);
    }

    /**
     * @param string|Stringable|MessageInterface|null $body
     * @param int $code
     * @param array<string,string> $headers
     * @return ResponseInterface
     */
    public static function createHtmlResponse(
        string|Stringable|MessageInterface|null $body,
        int $code = 200,
        array $headers = []
    ): ResponseInterface {
        if (is_object($body)) {
            $body = self::getBodyFromObject($body);
        }
        $body ??= '';
        $headers['Content-Type'] = ContentType::HTML;
        return self::createResponse($body, $code, $headers);
    }

    protected static function getBodyFromObject(Stringable|MessageInterface $object): string
    {
        if ($object instanceof MessageInterface) {
            return (string)$object->getBody();
        }
        return (string)$object;
    }
}

<?php

declare(strict_types=1);

namespace Kaly;

use Exception;
use Stringable;
use RuntimeException;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Http helpers
 *
 * We support three psr 7 providers:
 * - nyholm
 * - httpsoft
 * - guzzle
 *
 * Also acts as a ResponseFactoryInterface if needed
 */
class Http implements ResponseFactoryInterface
{
    public const CONTENT_TYPE_HTML = 'text/html';
    public const CONTENT_TYPE_CSS = 'text/css';
    public const CONTENT_TYPE_FORM = 'multipart/form-data';
    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_JS = 'application/javascript';

    /**
     * Create a request from $_SERVER globals
     * @link https://github.com/Nyholm/psr7-server
     * @link https://github.com/httpsoft/http-server-request
     * @link https://docs.guzzlephp.org/en/stable/psr7.html#requests
     */
    public static function createRequestFromGlobals(): ServerRequestInterface
    {
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
                $psr17Factory, // ServerRequestFactory
                $psr17Factory, // UriFactory
                $psr17Factory, // UploadedFileFactory
                $psr17Factory  // StreamFactory
            );
            $serverRequest = $creator->fromGlobals();
            return $serverRequest;
        }
        if (class_exists(\HttpSoft\ServerRequest\ServerRequestCreator::class)) {
            return \HttpSoft\ServerRequest\ServerRequestCreator::createFromGlobals();
        }
        if (class_exists(\GuzzleHttp\Psr7\ServerRequest::class)) {
            return \GuzzleHttp\Psr7\ServerRequest::fromGlobals();
        }
        throw new Exception("No suitable ServerRequestInterface implementation found");
    }

    /**
     * Finds a psr-7 response class that follows the status code, headers, body, version, reason convention
     * @group Response-Factory
     */
    public static function resolveResponseClass(): string
    {
        if (class_exists(\Nyholm\Psr7\Response::class)) {
            return \Nyholm\Psr7\Response::class;
        } elseif (class_exists(\HttpSoft\Message\Response::class)) {
            return \HttpSoft\Message\Response::class;
        } elseif (class_exists(\GuzzleHttp\Psr7\Response::class)) {
            return \GuzzleHttp\Psr7\Response::class;
        }
        throw new Exception("No suitable ResponseInterface implementation found");
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $class = self::resolveResponseClass();
        return new $class($code, [], '', '1.1', $reasonPhrase);
    }

    /**
     * @group Response-Factory
     * @param string $body
     * @param integer $code
     * @param array<string, string> $headers
     */
    public static function respond(string $body = "", int $code = 200, array $headers = []): ResponseInterface
    {
        $class = self::resolveResponseClass();
        return new $class($code, $headers, $body);
    }

    /**
     * @group Response-Factory
     */
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
        return self::respond($body, $code, $headers);
    }

    /**
     * Create a lightweight json response that minimize data transfer without adding
     * unnecessary stuff
     *
     * Strings are stored under the "message" key
     *
     * Clients are expected to check http status code, not response body
     *
     * @group Response-Factory
     * @param string|Stringable|JsonSerializable|array<string, mixed>|null $data
     * @param integer $code
     * @param array<string, string> $headers
     */
    public static function createJsonResponse($data, int $code = 200, array $headers = []): ResponseInterface
    {
        if (!$data) {
            $data = [];
        }
        if ($data instanceof Stringable) {
            $data = (string)$data;
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
        $headers['Content-Type'] = self::CONTENT_TYPE_JSON;
        return self::respond($body, $code, $headers);
    }

    /**
     * @group Response-Factory
     * @param string|Stringable|array<string, mixed>|null $body
     * @param integer $code
     * @param array<string, string> $headers
     */
    public static function createHtmlResponse($body, int $code = 200, array $headers = []): ResponseInterface
    {
        if ($body instanceof Stringable) {
            $body = (string)$body;
        } elseif (is_array($body)) {
            // Arrays are displayed as json
            $json = json_encode($body, JSON_PRETTY_PRINT);
            if (!$json) {
                $json = json_last_error_msg();
            }
            $body = "<pre>" . $json . "</pre>";
        } elseif ($body === null) {
            $body = '';
        }
        return self::respond($body, $code, $headers);
    }

    /**
     * Use middlewares/client-ip for a more complete solution
     */
    public static function getIp(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * @param array<string>|null $allowed
     */
    public static function getPreferredLanguage(ServerRequestInterface $request, array $allowed = null): ?string
    {
        $arr = self::parseAcceptedLanguages($request);
        if ($allowed === null) {
            return key($arr);
        }
        foreach ($arr as $k => $v) {
            if (in_array($k, $allowed)) {
                return $k;
            }
        }
        return $allowed[0];
    }

    /**
     * @return array<string, float>
     */
    public static function parseAcceptedLanguages(ServerRequestInterface $request): array
    {
        $header = $request->getHeader('Accept-Language')[0] ?? '';
        if (!$header) {
            $header = $request->getServerParams()['HTTP_ACCEPT_LANGUAGE'] ?? '';
        }
        $arr = [];
        foreach (explode(',', $header) as $part) {
            $subparts = explode(";q=", $part);
            $arr[$subparts[0]] = floatval($subparts[1] ?? 1);
        }
        arsort($arr);
        return $arr;
    }

    public static function getPreferredContentType(ServerRequestInterface $request, string $default = "text/plain"): ?string
    {
        return self::parseAcceptHeader($request)[0] ?? $default;
    }

    /**
     * @return array<string>
     */
    public static function parseAcceptHeader(ServerRequestInterface $request): array
    {
        $header = $request->getHeader('Accept')[0] ?? '';
        $arr = [];
        foreach (explode(',', $header) as $part) {
            $subparts = explode(';', $part);
            $mime = $subparts[0] ?? '';
            $types = explode('/', $mime);

            // Ignore invalid mimetypes
            if (!isset($types[1])) {
                continue;
            }

            $arr[] = $mime;
        }
        return $arr;
    }

    /**
     * Send a response
     */
    public static function sendResponse(ResponseInterface $response, int $bufferLength = null): void
    {
        if (headers_sent()) {
            throw new RuntimeException("Headers already sent");
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw new RuntimeException("Output already sent");
        }

        // Send headers
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', (string) $name))));
            $firstReplace = ($name === 'Set-Cookie') ? false : true;

            foreach ($values as $value) {
                header("{$name}: {$value}", $firstReplace);
                $firstReplace = false;
            }
        }

        // Get the body
        $body = $response->getBody();

        // If the body is not readable do not send any body to the client
        if (!$body->isReadable()) {
            return;
        }

        // Send the body, no need to rewind because we send the whole thing
        if (null === $bufferLength) {
            echo $body->__toString();
            return;
        }

        // Or send by chunk
        flush();

        // Content-Range support
        $range = self::parseContentRange($response->getHeaderLine('Content-Range'));
        if (isset($range['unit']) && $range['unit'] === 'bytes') {
            self::emitBodyRange($body, $range['first'], $range['last'], $bufferLength);
            return;
        }

        // Rewind if possible
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Read by buffer length until end of file
        while (!$body->eof()) {
            echo $body->read($bufferLength);
        }
    }

    /**
     * Emits a range of the message body.
     * @group Content-Range
     */
    private static function emitBodyRange(StreamInterface $body, int $first, int $last, int $bufferLength = 4096): void
    {
        // Total length to process
        $length = $last - $first + 1;

        // Go to first byte
        if ($body->isSeekable()) {
            $body->seek($first);
        }

        // Process by chunk
        while ($length >= $bufferLength && !$body->eof()) {
            $contents = $body->read($bufferLength);
            $length -= strlen($contents);
            echo $contents;
        }

        // Send the remaining
        if ($length > 0 && !$body->eof()) {
            echo $body->read($length);
        }
    }

    /**
     * Parse Content-Range header.
     *
     * @group Content-Range
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.16
     * @return array{"unit": mixed, "first": int, "last": int, "length": '*'|int}|null
     */
    private static function parseContentRange(string $header): ?array
    {
        if (preg_match('/(?P<unit>[\w]+)\s+(?P<first>\d+)-(?P<last>\d+)\/(?P<length>\d+|\*)/', $header, $matches)) {
            return [
                'unit' => $matches['unit'],
                'first' => (int) $matches['first'],
                'last' => (int) $matches['last'],
                'length' => ($matches['length'] === '*') ? '*' : (int) $matches['length'],
            ];
        }

        return null;
    }
}

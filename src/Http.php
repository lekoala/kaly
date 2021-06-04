<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;
use Nyholm\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;

/**
 * Http
 */
class Http
{
    public const CONTENT_TYPE_HTML = 'text/html';
    public const CONTENT_TYPE_CSS = 'text/css';
    public const CONTENT_TYPE_FORM = 'multipart/form-data';
    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_JS = 'application/javascript';

    /**
     * Create a request from $_SERVER globals
     */
    public static function createRequestFromGlobals(): ServerRequestInterface
    {
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

    public static function createRedirectResponse(string $url, int $code = 307, string $body = ''): Response
    {
        if ($code < 300 || $code > 399) {
            throw new InvalidArgumentException("$code should be between 300 and 399");
        }
        $headers['Location'] = $url;
        if (!$body) {
            $body = 'You are being redirected to ' . $url;
        }
        return new Response($code, $headers, $body);
    }

    /**
     * Create a lightweight json response that minimize data transfer without adding
     * unnecessary stuff
     *
     * The response contains:
     * - a `data` key when it returns an array of data (data rows or struct)
     * - a `message` key for simple messages (status messages)
     * - a `errors` key with an array of errors (validation and errors)
     *
     * @param integer $code
     * @param mixed $data
     * @param array<string, string> $headers
     */
    public static function createJsonResponse(int $code, $data, array $headers = []): Response
    {
        $arr = [];
        if (400 <= $code && $code <= 599) {
            if (is_string($data)) {
                $data = [$data];
            }
            $arr['errors'] = $data;
        } else {
            if (!is_array($data)) {
                $arr['message'] = $data;
            } else {
                $arr['data'] = $data;
            }
        }
        $body = json_encode($arr);

        // We couldn't encode the array, output errors
        if (!$body) {
            $code = 500;
            $body = '{"errors":["' . json_last_error_msg() . '"]}';
        }
        $headers['Content-Type'] = self::CONTENT_TYPE_JSON;
        return new Response($code, $headers, $body);
    }

    /**
     * @param integer $code
     * @param mixed $body
     * @param array<string, string> $headers
     */
    public static function createHtmlResponse(int $code, $body, array $headers = []): Response
    {
        if ($body instanceof Stringable) {
            $body = (string)$body;
        } elseif (is_array($body)) {
            $body = json_encode($body);
            if (!$body) {
                $body = json_last_error_msg();
            }
        }
        return new Response($code, $headers, $body);
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

        // content-range support
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

<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Psr\Http\Message\StreamInterface;
use InvalidArgumentException;

/**
 * @link https://github.com/laminas/laminas-httphandlerrunner/blob/2.11.x/src/Emitter/SapiEmitter.php
 * @link https://github.com/httpsoft/http-emitter/blob/master/src/SapiEmitter.php
 */
class ResponseEmitter implements ResponseEmitterInterface
{
    public const EMPTY_RESPONSES = [100, 101, 102, 204, 205, 304];

    /**
     * @var int|null
     */
    protected ?int $bufferLength;

    /**
     * @param int|null $bufferLength
     * @throws InvalidArgumentException if buffer length is integer type and less than or one.
     */
    public function __construct(?int $bufferLength = null)
    {
        if ($bufferLength !== null && $bufferLength < 1) {
            throw new InvalidArgumentException(sprintf(
                'Buffer length for `%s` must be greater than zero; received `%d`',
                self::class,
                $bufferLength
            ));
        }

        $this->bufferLength = $bufferLength;
    }

    /**
     * Emits a response for a PHP SAPI environment.
     *
     * Emits the status line and headers via the header() function, and the
     * body content via the output buffer.
     */
    public function emit(ResponseInterface $response): bool
    {
        $this->assertNoPreviousOutput();

        $this->emitHeaders($response);
        $this->emitStatusLine($response);
        $this->emitBody($response);

        return true;
    }

    /**
     * Checks to see if content has previously been sent.
     *
     * If either headers have been sent or the output buffer contains content,
     * raises an exception.
     */
    private function assertNoPreviousOutput(): void
    {
        if (headers_sent($filename, $line)) {
            throw new RuntimeException("Headers already sent in $filename on line $line");
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw new RuntimeException("Output already sent");
        }
    }

    /**
     * Emit response headers.
     *
     * Loops through each header, emitting each; if the header value
     * is an array with multiple values, ensures that each is sent
     * in such a way as to create aggregate headers (instead of replace
     * the previous).
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', (string) $name))));
            $firstReplace = ($name === 'Set-Cookie') ? false : true;

            foreach ($values as $value) {
                header("{$name}: {$value}", $firstReplace);
                $firstReplace = false;
            }
        }
    }

    /**
     * Emit the status line.
     *
     * Emits the status line using the protocol version and status code from
     * the response; if a reason phrase is available, it, too, is emitted.
     *
     * It is important to mention that this method should be called after
     * `emitHeaders()` in order to prevent PHP from changing the status code of
     * the emitted response.
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $reasonPhrase = $response->getReasonPhrase();
        $statusCode = $response->getStatusCode();
        $protocol = $response->getProtocolVersion();

        header(
            sprintf('HTTP/%s %s %s', $protocol, $statusCode, $reasonPhrase),
            true,
            $statusCode
        );
    }

    /**
     * Emit the message body.
     */
    private function emitBody(ResponseInterface $response): void
    {
        // Don't emit body for empty responses
        if (in_array($response->getStatusCode(), self::EMPTY_RESPONSES)) {
            return;
        }

        // Get the body
        $body = $response->getBody();

        // If the body is not readable do not send any body to the client
        if (!$body->isReadable()) {
            return;
        }

        // Content-Range support
        $range = self::parseContentRange($response->getHeaderLine('Content-Range'));
        if (isset($range['unit']) && $range['unit'] === 'bytes') {
            self::emitBodyRange($body, $range['first'], $range['last'], $this->bufferLength);
            return;
        }

        // Send the body, no need to rewind because we send the whole thing
        if (null === $this->bufferLength) {
            echo $body->__toString();
            return;
        }

        // Or send by chunk
        flush();

        // Rewind if possible
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Read by buffer length until end of file
        while (!$body->eof()) {
            echo $body->read($this->bufferLength);
        }
    }

    /**
     * Emits a range of the message body.
     */
    private static function emitBodyRange(StreamInterface $body, int $first, int $last, ?int $bufferLength = null): void
    {
        if ($bufferLength === null) {
            $bufferLength = 4096;
        }

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
     * @return array{unit:mixed,first:int,last:int,length:'*'|int}|null
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

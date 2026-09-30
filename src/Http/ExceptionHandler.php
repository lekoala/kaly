<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Util\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default exception handler, built on the PSR-17 factories of the app.
 *
 * - An HTTP exception is an expected outcome: its status, headers and public
 *   body are used as is. A generic exception is an error: it is logged and
 *   becomes a 500 (or its own 4xx/5xx code).
 * - The format follows the client: `application/problem+json` (RFC 9457) for
 *   a JSON client, HTML or plain text otherwise.
 * - In debug mode, the response explains the failure (the bound
 *   DebugPageInterface, or an `exception` member in JSON). In production,
 *   nothing internal leaks: a generic exception or a 404 only says what the
 *   status says.
 */
class ExceptionHandler implements ExceptionHandlerInterface
{
    public const PROBLEM_JSON = 'application/problem+json';

    public function __construct(
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?LoggerInterface $logger = null,
        protected bool $debug = false,
        protected ?DebugPageInterface $debugPage = null,
    ) {}

    public function toResponse(Throwable $exception, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $isHttp = $exception instanceof HttpExceptionInterface;
        if (!$isHttp) {
            $this->logger?->error($exception->getMessage(), ['exception' => $exception]);
        }

        $status = $isHttp ? $exception->status() : (int) $exception->getCode();
        if ($status < 100 || $status > 599 || !$isHttp && $status < 400) {
            $status = 500;
        }
        $response = $this->responseFactory->createResponse($status);

        $headers = $isHttp ? $exception->getResponseHeaders() : [];
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // A public body the exception chose for itself (redirect, validation
        // message, explicit html/json...). A generic exception never has one.
        $body = $isHttp ? $exception->getResponseBody() : '';

        // The exception already decided the format
        if ($response->hasHeader('Content-Type')) {
            return $this->withBody($response, $body);
        }

        if ($request !== null && Accept::prefersJson($request)) {
            return $this->problem($response, $exception, $body);
        }

        if ($body !== '') {
            return $this->withBody($response->withHeader('Content-Type', 'text/plain; charset=utf-8'), $body);
        }

        if ($this->debug) {
            if ($this->debugPage !== null) {
                $html = $this->debugPage->html($exception, $request, $status);
                return $this->withBody($response->withHeader('Content-Type', ContentType::HTML . '; charset=utf-8'), $html);
            }
            // Without a bound debug page the failure is still explained, in plain text
            return $this->withBody(
                $response->withHeader('Content-Type', 'text/plain; charset=utf-8'),
                $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
            );
        }

        $public = $status === 500 ? 'Server error' : $response->getReasonPhrase();
        return $this->withBody($response->withHeader('Content-Type', 'text/plain; charset=utf-8'), $public);
    }

    /**
     * A problem details document (RFC 9457)
     */
    protected function problem(ResponseInterface $response, Throwable $exception, string $body): ResponseInterface
    {
        $problem = [
            'type' => 'about:blank',
            'title' => $response->getReasonPhrase(),
            'status' => $response->getStatusCode(),
        ];
        if ($body !== '') {
            $problem['detail'] = $body;
        }
        if ($this->debug) {
            $problem['detail'] ??= $exception->getMessage();
            $problem['exception'] = [];
            for ($ex = $exception; $ex !== null; $ex = $ex->getPrevious()) {
                $problem['exception'][] = [
                    'class' => $ex::class,
                    'message' => $ex->getMessage(),
                    'file' => $ex->getFile(),
                    'line' => $ex->getLine(),
                    'trace' => explode("\n", $ex->getTraceAsString()),
                ];
            }
        }

        return $this->withBody($response->withHeader('Content-Type', self::PROBLEM_JSON), Json::encode($problem));
    }

    protected function withBody(ResponseInterface $response, string $body): ResponseInterface
    {
        return $response->withBody($this->streamFactory->createStream($body));
    }
}

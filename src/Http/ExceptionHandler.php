<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\ErrorHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default exception handler built on the PSR-17 factories provided by the app.
 */
class ExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function toResponse(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $this->createResponse($exception->getResponseBody(), $exception->getIntCode(), $exception->getResponseHeaders());
        }

        // Only a valid HTTP error status is meaningful, anything else is a server error
        $code = $exception->getCode();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }

        $body = ErrorHandler::generateError($exception, $this->logger);

        return $this->createResponse($body, $code);
    }

    /**
     * @param array<string,string> $headers
     */
    protected function createResponse(string $body, int $code, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($code);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response->withBody($this->streamFactory->createStream($body));
    }
}

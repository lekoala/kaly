<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\HttpExceptionInterface;
use Kaly\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Stateless request kernel.
 *
 * It is built once during boot and holds no per-request state, so the same
 * instance can safely handle many requests (eg: in a worker).
 */
final class Kernel implements RequestHandlerInterface
{
    /**
     * Exceptions thrown by the error callbacks themselves, kept for debugging.
     *
     * @var Throwable[]
     */
    private array $callbackErrors = [];

    /**
     * @param Closure(string, mixed...): void $callbacks The application callback runner
     */
    public function __construct(
        protected RequestHandlerInterface $handler,
        protected ExceptionHandlerInterface $exceptionHandler,
        protected Closure $callbacks,
    ) {}

    /**
     * @return Throwable[]
     */
    public function getCallbackErrors(): array
    {
        return $this->callbackErrors;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = ServerRequest::createFromRequest($request);

        try {
            ($this->callbacks)(Application::CB_BEFORE_REQUEST, $request);
        } catch (Throwable $ex) {
            $response = $this->handleException($ex);
            $this->runAfterRequest($request);
            return $response;
        }

        try {
            $response = $this->handler->handle($request);
        } catch (Throwable $ex) {
            $response = $this->handleException($ex);
        }

        $this->runAfterRequest($request);

        return $response;
    }

    /**
     * Convert an exception into a response, reporting non-HTTP errors.
     */
    private function handleException(Throwable $ex): ResponseInterface
    {
        // Generic errors get a chance to be reported, HTTP exceptions are expected
        if (!$ex instanceof HttpExceptionInterface) {
            try {
                ($this->callbacks)(Application::CB_ERROR, $ex);
            } catch (Throwable $callbackError) {
                // A broken error callback must not prevent the error response
                $this->callbackErrors[] = $callbackError;
            }
        }

        return $this->exceptionHandler->toResponse($ex);
    }

    /**
     * Run the afterRequest callbacks without letting them mask the response.
     */
    private function runAfterRequest(ServerRequestInterface $request): void
    {
        try {
            ($this->callbacks)(Application::CB_AFTER_REQUEST, $request);
        } catch (Throwable $ex) {
            if (!$ex instanceof HttpExceptionInterface) {
                try {
                    ($this->callbacks)(Application::CB_ERROR, $ex);
                } catch (Throwable $callbackError) {
                    $this->callbackErrors[] = $callbackError;
                }
            }
        }
    }
}

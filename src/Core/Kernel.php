<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\HttpContext;
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
 * instance can safely handle many requests (eg: in a worker). Everything that
 * belongs to one cycle lives in the HttpContext it creates:
 *
 * ```text
 * one request -> one context -> the whole cycle -> one response
 * ```
 */
final class Kernel implements RequestHandlerInterface
{
    /**
     * @param Closure(string, mixed...): void $callbacks The application callback runner
     */
    public function __construct(
        protected RequestHandlerInterface $handler,
        protected ExceptionHandlerInterface $exceptionHandler,
        protected Closure $callbacks,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = ServerRequest::createFromRequest($request);

        $ctx = new HttpContext($request);
        $ctx->bind($request);

        try {
            ($this->callbacks)(Application::CB_BEFORE_REQUEST, $ctx);

            $ctx->response = $this->handler->handle($ctx->request);
        } catch (Throwable $ex) {
            $ctx->response = $this->handleException($ex, $ctx);
        }

        $this->runAfterRequest($ctx);

        assert($ctx->response !== null);

        return $ctx->response;
    }

    /**
     * Convert an exception into a response, reporting non-HTTP errors.
     */
    private function handleException(Throwable $ex, HttpContext $ctx): ResponseInterface
    {
        // Generic errors get a chance to be reported, HTTP exceptions are expected
        if (!$ex instanceof HttpExceptionInterface) {
            try {
                ($this->callbacks)(Application::CB_ERROR, $ex, $ctx);
            } catch (Throwable $callbackError) {
                // A broken error callback must not prevent the error response
                $ctx->addCallbackError($callbackError);
            }
        }

        return $this->exceptionHandler->toResponse($ex);
    }

    /**
     * Run the afterRequest callbacks without letting them mask the response.
     */
    private function runAfterRequest(HttpContext $ctx): void
    {
        try {
            ($this->callbacks)(Application::CB_AFTER_REQUEST, $ctx);
        } catch (Throwable $ex) {
            if (!$ex instanceof HttpExceptionInterface) {
                try {
                    ($this->callbacks)(Application::CB_ERROR, $ex, $ctx);
                } catch (Throwable $callbackError) {
                    $ctx->addCallbackError($callbackError);
                }
            }
        }
    }
}

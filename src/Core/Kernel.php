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

        ($this->callbacks)(Application::CB_BEFORE_REQUEST, $request);

        try {
            return $this->handler->handle($request);
        } catch (Throwable $ex) {
            // Generic errors get a chance to be reported, HTTP exceptions are expected
            if (!$ex instanceof HttpExceptionInterface) {
                ($this->callbacks)(Application::CB_ERROR, $ex);
            }

            return $this->exceptionHandler->toResponse($ex);
        } finally {
            ($this->callbacks)(Application::CB_AFTER_REQUEST, $request);
        }
    }
}

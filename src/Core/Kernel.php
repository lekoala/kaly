<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Core\Middleware\OutgoingRunner;
use Kaly\Http\CookiePolicy;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\SessionProviderInterface;
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
 *
 * pipeline (incoming, routing, routed, route middlewares, dispatcher)
 *   -> commit (session, cookies)
 *   -> outgoing
 *   -> terminate hooks
 * ```
 */
final class Kernel implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private ExceptionHandlerInterface $exceptionHandler,
        private Hooks $hooks = new Hooks(),
        private ?OutgoingRunner $outgoing = null,
        private ?SessionProviderInterface $sessionProvider = null,
        private ?CookiePolicy $cookiePolicy = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = new HttpContext($request, $this->sessionProvider, $this->cookiePolicy);
        $ctx->bind($request);

        // First boundary: produce a response, from the happy path or from an
        // exception escaping the request pipeline.
        try {
            $response = $this->handler->handle($ctx->request());
        } catch (Throwable $ex) {
            $response = $this->handleException($ex, $ctx);
        }

        // The state owned by the context is written back whatever the origin
        // of the response: session and cookie changes are never lost
        try {
            $response = $ctx->commit($response);
        } catch (Throwable $ex) {
            $response = $this->handleException($ex, $ctx);
        }

        // Second boundary: the outgoing phase sees the response whatever its
        // origin. It is attempted once for every response: if an outgoing
        // middleware throws, the phase stops and the exception is converted to
        // a new error response, on which only the `always` middlewares run.
        if ($this->outgoing !== null) {
            try {
                $response = $this->outgoing->process($response, $ctx);
            } catch (Throwable $ex) {
                $response = $this->outgoing->process($this->handleException($ex, $ctx), $ctx, recovering: true);
            }
        }

        // The cycle is over: from here on the context exposes its response
        $ctx->complete($response);

        $this->hooks->terminate($ctx);

        return $response;
    }

    /**
     * Convert an exception into a response, reporting generic errors.
     */
    private function handleException(Throwable $ex, HttpContext $ctx): ResponseInterface
    {
        $this->hooks->error($ex, $ctx);

        return $this->exceptionHandler->toResponse($ex, $ctx->request());
    }
}

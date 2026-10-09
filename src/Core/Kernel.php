<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Core\Middleware\OutgoingRunner;
use Kaly\Debug\Profile;
use Kaly\Debug\ServerTiming;
use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Session\SessionProviderInterface;
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
 *   -> outgoing
 *   -> commit (session storage, session cookie, cookies)
 *   -> final profile export (optional)
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
        private bool $profiling = false,
        private bool $serverTiming = false,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = new HttpContext($request, $this->sessionProvider, $this->cookiePolicy, $this->profiling ? new Profile() : null);
        $ctx->bind($request);

        try {
            return $this->handleContext($ctx);
        } finally {
            // A terminate or error hook may reopen committed storage. Release
            // it even when response production or error recovery throws.
            $ctx->closeSession();
        }
    }

    private function handleContext(HttpContext $ctx): ResponseInterface
    {
        $start = $ctx->profile() !== null ? hrtime(true) : null;
        try {
            $response = $this->produceResponse($ctx);
        } finally {
            if ($start !== null) {
                $ctx->profile()?->record('request', hrtime(true) - $start);
            }
        }
        if ($this->serverTiming && $ctx->profile() !== null) {
            $response = ServerTiming::apply($response, $ctx->profile());
        }
        $ctx->complete($response);
        $this->hooks->terminate($ctx);

        return $response;
    }

    private function produceResponse(HttpContext $ctx): ResponseInterface
    {
        // First boundary: produce a response, from the happy path or from an
        // exception escaping the request pipeline.
        try {
            $response = $this->handler->handle($ctx->request());
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

        // The state the cycle established is committed last, on the final
        // response: the session is persisted and its cookie emitted, cookie
        // changes become headers. If the commit itself fails, the error
        // response still runs through the `always` middlewares, without
        // retrying the failed commit.
        $start = $ctx->profile() !== null ? hrtime(true) : null;
        try {
            try {
                $response = $ctx->commit($response);
            } finally {
                if ($start !== null) {
                    $ctx->profile()?->record('commit', hrtime(true) - $start);
                }
            }
        } catch (Throwable $ex) {
            $response = $this->handleException($ex, $ctx);
            if ($this->outgoing !== null) {
                $response = $this->outgoing->process($response, $ctx, recovering: true);
            }
        }

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

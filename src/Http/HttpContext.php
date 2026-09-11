<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Router\Route;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The internal model of a request cycle.
 *
 * PSR-7/15 remain the interop boundary, but the state established while
 * handling a request lives here instead of being probed from request
 * attributes. There is a single Kaly request attribute, the context itself,
 * and everything else lives inside it.
 *
 * The context is mutable for the duration of one request, while the PSR
 * messages it carries stay immutable: assign a new message rather than
 * mutating one.
 *
 * ```php
 * $ctx->request = $ctx->request->withAttribute('foo', 'bar');
 * $ctx->response = $ctx->response?->withHeader('X-Foo', 'bar');
 * ```
 */
final class HttpContext
{
    /**
     * The one and only request attribute used by kaly
     */
    public const ATTRIBUTE = self::class;

    /**
     * The last known response of the cycle. It is null until something
     * produced one (typically the dispatcher at the end of the pipeline).
     */
    public ?ResponseInterface $response = null;

    /**
     * The route matched by the routing handler.
     * The module is available in a typed way through $ctx->route?->module
     */
    public ?Route $route = null;

    /**
     * The locale the request runs with, resolved during routing
     */
    public ?string $locale = null;

    /**
     * An optional correlation id, typically set by an incoming middleware
     */
    public ?string $requestId = null;

    /**
     * Middlewares that actually entered the stack, in execution order.
     * This is diagnostic information: never use it to decide whether a piece
     * of state is available, read the typed properties instead.
     *
     * @var list<class-string>
     */
    private array $middlewares = [];

    /**
     * Exceptions thrown by the callbacks themselves, kept for debugging
     *
     * @var list<Throwable>
     */
    private array $callbackErrors = [];

    public function __construct(
        public ServerRequestInterface $request,
    ) {}

    /**
     * Get the context attached to a request. This is how a kaly middleware
     * gets access to the current cycle:
     *
     * ```php
     * $ctx = HttpContext::from($request);
     * ```
     */
    public static function from(ServerRequestInterface $request): self
    {
        $ctx = self::tryFrom($request);

        if ($ctx === null) {
            throw new LogicException('No HTTP context is attached to the request.');
        }

        return $ctx;
    }

    /**
     * Same as from() but returns null instead of throwing
     */
    public static function tryFrom(ServerRequestInterface $request): ?self
    {
        $ctx = $request->getAttribute(self::ATTRIBUTE);

        return $ctx instanceof self ? $ctx : null;
    }

    /**
     * Get the context attached to a request, creating and binding one if
     * needed. The up to date request is always available as $ctx->request.
     */
    public static function ensure(ServerRequestInterface $request): self
    {
        $ctx = self::tryFrom($request) ?? new self($request);
        $ctx->bind($request);

        return $ctx;
    }

    /**
     * Attach this context to a request and remember it as the current one.
     *
     * Rebinding matters because a third party middleware is free to hand over
     * a brand new request object: the pipeline reattaches the context so that
     * the cycle is never lost.
     */
    public function bind(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getAttribute(self::ATTRIBUTE) !== $this) {
            $request = $request->withAttribute(self::ATTRIBUTE, $this);
        }
        $this->request = $request;

        return $request;
    }

    /**
     * The client ip as reported by the server, without any proxy resolution.
     * A trusted proxy middleware can overwrite REMOTE_ADDR on the request.
     */
    public function clientIp(): string
    {
        $ip = $this->request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * Flag a middleware as entered. The pipeline does this on its own: a
     * middleware never has to register itself.
     *
     * @param class-string $class
     */
    public function markMiddleware(string $class): void
    {
        $this->middlewares[] = $class;
    }

    /**
     * The middlewares that really ran, not the ones that were merely
     * registered or whose condition failed.
     *
     * @return list<class-string>
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * @param class-string $class
     */
    public function hasMiddleware(string $class): bool
    {
        return in_array($class, $this->middlewares, true);
    }

    public function addCallbackError(Throwable $ex): void
    {
        $this->callbackErrors[] = $ex;
    }

    /**
     * @return list<Throwable>
     */
    public function callbackErrors(): array
    {
        return $this->callbackErrors;
    }
}

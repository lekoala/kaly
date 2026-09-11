<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\Cookies;
use Kaly\Http\Session;
use Kaly\Router\Route;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The request scoped state of the application.
 *
 * PSR-7/15 remain the interop boundary, but what a request has established
 * lives here instead of being probed from request attributes. There is a
 * single kaly request attribute, the context itself, and everything else lives
 * inside it.
 *
 * State is established once and read through strict accessors: behind the
 * routing step `route()` and `locale()` are guaranteed, and calling them too
 * early is a programming error rather than a null that contaminates every
 * caller downstream.
 */
final class HttpContext
{
    /**
     * The one and only request attribute used by kaly
     */
    public const ATTRIBUTE = self::class;

    private ?ResponseInterface $response = null;

    private ?Route $route = null;

    private ?string $locale = null;

    private ?Session $session = null;

    private ?Cookies $cookies = null;

    /**
     * Middlewares that actually entered the stack, in execution order.
     * This is diagnostic information: never use it to decide whether a piece
     * of state is available, read the accessors instead.
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
        private ServerRequestInterface $request,
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
     * needed. The up to date request is always available as $ctx->request().
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
     *
     * The request handed to a middleware is always the truth; this simply
     * keeps the context in sync with it.
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
     * The current PSR-7 request. It is immutable: to change it, hand a new
     * request to the next handler and the pipeline will track it.
     */
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * The route matched by the routing handler. It is guaranteed for anything
     * running in the routed band or later.
     */
    public function route(): Route
    {
        return $this->route ?? throw new LogicException('Request has not been routed yet.');
    }

    public function hasRoute(): bool
    {
        return $this->route !== null;
    }

    /**
     * @internal Set by the routing handler
     */
    public function useRoute(Route $route): void
    {
        $this->route = $route;
    }

    /**
     * The locale the request runs with. It is guaranteed for anything running
     * in the routed band or later.
     */
    public function locale(): string
    {
        return $this->locale ?? throw new LogicException('Locale has not been resolved yet.');
    }

    public function hasLocale(): bool
    {
        return $this->locale !== null;
    }

    /**
     * Impose a locale. An incoming middleware can do this to override content
     * negotiation; a locale carried by the route still wins over it.
     */
    public function useLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * The final response of the request. It is only available once the cycle
     * is finalized, typically in an afterRequest callback.
     */
    public function response(): ResponseInterface
    {
        return $this->response ?? throw new LogicException('Response is not available yet.');
    }

    public function hasResponse(): bool
    {
        return $this->response !== null;
    }

    /**
     * @internal Called once by the kernel when the cycle is over
     */
    public function complete(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * The session of this request. The context owns it, so the same instance
     * is shared for the whole cycle and its dirty tracking stays meaningful.
     *
     * The session is not actually started unless open() or set() is called.
     */
    public function session(): Session
    {
        return $this->session ??= new Session([], $this->request);
    }

    /**
     * The cookies of this request. The context owns it, so the cookies
     * received are snapshotted once and changes accumulate over the cycle.
     */
    public function cookies(): Cookies
    {
        return $this->cookies ??= new Cookies($this->request);
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
     * @internal Flagged by the pipeline, a middleware never registers itself
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

    /**
     * @internal Reported by the kernel when a callback itself fails
     */
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

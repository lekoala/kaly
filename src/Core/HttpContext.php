<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Auth\Authentication;
use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\Cookie\Cookies;
use Kaly\Http\Csp\Csp;
use Kaly\Http\RequestUtils;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
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
 * routing step `route()`, `locale()` and the contextual `url()`/`urlFor()`
 * are guaranteed, and calling them too early is a programming error rather
 * than a null that contaminates every caller downstream.
 */
final class HttpContext
{
    /**
     * The one and only request attribute used by kaly
     */
    public const ATTRIBUTE = self::class;

    private ?ResponseInterface $response = null;

    private ?Route $route = null;

    private ?RouterInterface $router = null;

    private ?string $locale = null;

    private ?SessionInterface $session = null;

    private ?Cookies $cookies = null;

    private ?Authentication $auth = null;

    private ?Csp $csp = null;

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
        private ?SessionProviderInterface $sessionProvider = null,
        private ?CookiePolicy $cookiePolicy = null,
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
            throw new LogicException('No HTTP context is attached to the request');
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
     * The route matched by the routing handler.
     *
     * It is guaranteed while executing the routed request phase and the
     * dispatcher. It is not guaranteed in the outgoing phase, because outgoing
     * also receives responses produced before routing completed (an incoming
     * short-circuit or a routing 404): check hasRoute() first.
     */
    public function route(): Route
    {
        return $this->route ?? throw new LogicException('Request has not been routed yet');
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
     * @internal Set by the routing handler
     */
    public function useRouter(RouterInterface $router): void
    {
        $this->router = $router;
    }

    /**
     * The url of a named route, generated for the locale of this request.
     *
     * @param array<string,mixed> $params Placeholders; the others become the query string
     */
    public function url(string $name, array $params = []): string
    {
        return $this->router()->url($name, $params, $this->locale());
    }

    /**
     * The conventional url of an action, generated for the locale of this request.
     *
     * @param string|array<mixed> $handler [Controller::class, 'action'], Controller::class or 'Controller::action'
     * @param array<array-key,mixed> $params Action arguments, in order
     */
    public function urlFor(string|array $handler, array $params = []): string
    {
        return $this->router()->urlFor($handler, $params, $this->locale());
    }

    private function router(): RouterInterface
    {
        return $this->router ?? throw new LogicException('Router has not been set on the context yet');
    }

    /**
     * The locale the request runs with.
     *
     * It is guaranteed while executing the routed request phase and the
     * dispatcher. It is not guaranteed in the outgoing phase, because outgoing
     * also receives responses produced before the locale was resolved: check
     * hasLocale() first.
     */
    public function locale(): string
    {
        return $this->locale ?? throw new LogicException('Locale has not been resolved yet');
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
     * is finalized, typically in an onTerminate() hook.
     */
    public function response(): ResponseInterface
    {
        return $this->response ?? throw new LogicException('Response is not available yet');
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
     * is shared for the whole cycle.
     *
     * The session is created lazily and started on first access. The storage
     * comes from the session provider given at construction. The context does
     * not choose a session backend: the application injects a provider (the
     * native provider by default), or the caller may impose an externally
     * managed session with useSession(). A context without a provider cannot
     * create a session and says so.
     */
    public function session(): SessionInterface
    {
        return $this->session ??= $this->sessionProvider()->create($this->request);
    }

    /**
     * Impose a session storage for this request cycle.
     */
    public function useSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    /**
     * The cookies of this request. The context owns it, so the cookies
     * received are snapshotted once and changes accumulate over the cycle.
     */
    public function cookies(): Cookies
    {
        return $this->cookies ??= new Cookies($this->request, $this->cookiePolicy());
    }

    /**
     * The identity established for this request. The context owns it, so the
     * same instance is shared for the whole cycle.
     */
    public function auth(): Authentication
    {
        return $this->auth ??= new Authentication();
    }

    /**
     * The Content Security Policy nonce of this response. The context owns
     * it, so templates and the outgoing CSP header share the same value for
     * the whole cycle. Nothing is generated until first use.
     */
    public function csp(): Csp
    {
        return $this->csp ??= new Csp();
    }

    /**
     * @internal Called once by the kernel on the final response, after the
     * outgoing phase. Commits the state the cycle changed: the session storage
     * is written back and its cookie emitted if needed, the cookie changes
     * become Set-Cookie headers. Committing last is what keeps a session or a
     * cookie set before an outgoing failure from being lost. A session or
     * cookie jar never touched costs nothing.
     */
    public function commit(ResponseInterface $response): ResponseInterface
    {
        if ($this->session !== null && $this->sessionProvider !== null) {
            $response = $this->sessionProvider->commit($this->session, $this->request, $response);
        }
        if ($this->cookies !== null) {
            $response = $this->cookies->addToResponse($response);
        }

        return $response;
    }

    private function sessionProvider(): SessionProviderInterface
    {
        return $this->sessionProvider ?? throw new LogicException('No session provider is configured');
    }

    private function cookiePolicy(): CookiePolicy
    {
        return $this->cookiePolicy ??= CookiePolicy::baseline();
    }

    /**
     * The client ip as reported by the server, without any proxy resolution.
     * A trusted proxy middleware can overwrite REMOTE_ADDR on the request.
     */
    public function clientIp(): string
    {
        return RequestUtils::getIp($this->request);
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

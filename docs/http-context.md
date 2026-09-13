# Http context

PSR-7 and PSR-15 give an excellent interop protocol, but not an application model.
When everything is "just a middleware", you lose the notion of phase, of dependency
and of already established context.

Kaly keeps PSR as the boundary and adds `Kaly\Core\HttpContext` as the request scoped
state of the application: **PSR for interop, `HttpContext` for richness, bands for
order**.

```text
one request -> one context -> the whole cycle -> one response
```

## What it holds

```php
$ctx->request();    // the current PSR-7 request
$ctx->route();      // the matched route
$ctx->locale();     // the locale of the request
$ctx->session();    // the session of this request
$ctx->cookies();    // the cookies of this request
$ctx->response();   // the final response, once the cycle is over
```

Instead of probing the request:

```php
$route = $request->getAttribute('route');
$locale = $request->getAttribute('locale');
if ($router !== null) { ... }
```

you read established state directly:

```php
$ctx->route()->module;
$ctx->locale();
```

## Strict accessors, not nullables

The accessors are strict on purpose. Behind the routing step — that is, in the routed
band, in the dispatcher and in every controller — `route()` and `locale()` are
**guaranteed**. Calling them earlier is a programming error and throws a
`LogicException` instead of returning a null that then contaminates every caller
downstream.

That is exactly what removes the need for capability checks everywhere: you never ask
*"do we have a route?"*, the band you registered in already answers it.

For genuinely optional cases — an error reporter that may run before routing, a debug
toolbar — there is a matching `has*()`:

```php
$app->addCallback(App::CB_ERROR, function (Throwable $e, HttpContext $ctx): void {
    myTracker()->report($e, [
        'route' => $ctx->hasRoute() ? $ctx->route()->controller : null,
    ]);
});
```

An incoming middleware can still impose a locale before anything is routed, and a
locale carried by the route still wins over it:

```php
$ctx->useLocale('fr');
```

## Getting it

The context travels as the one and only kaly request attribute, so third party PSR-15
middlewares simply ignore it and nothing new has to be implemented:

```php
use Kaly\Core\HttpContext;

public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $handler,
): ResponseInterface {
    $ctx = HttpContext::from($request);

    // ...

    return $handler->handle($request);
}
```

- `HttpContext::from($request)` throws when no context is attached.
- `HttpContext::tryFrom($request)` returns `null` instead.
- `HttpContext::ensure($request)` creates and binds one if needed.

In a controller extending `Kaly\Core\AbstractController`, use the `ctx()` helper:

```php
public function index(): string
{
    return $this->ctx()->locale();
}
```

> Request attributes are not meant to be used as a general application storage
> anymore. Keep a single kaly attribute, the context, and put the rest inside it.

## Request and response

The PSR messages stay immutable, and there is exactly one way to change the request:
hand a new one to the next handler. The pipeline tracks it, so `$ctx->request()` is
always the current one.

```php
return $handler->handle($request->withAttribute('foo', 'bar'));
```

Every step rebinds the context, so a middleware handing over a brand new request
object never loses the cycle.

The response is **not** mirrored during the unwind: a middleware already owns the one
returned by its own handler.

```php
$response = $handler->handle($request);
return $response->withHeader('X-Foo', 'bar');
```

`$ctx->response()` has a narrower and sharper meaning: the **final** response of the
request, available from finalization onwards, which in practice means in an
`afterRequest` callback. Before that, it throws (`hasResponse()` tells you).

## Session and cookies

The context owns them, so the same instance is shared for the whole cycle:

```php
$ctx->session()->set('user', $id);
$ctx->cookies()->set('theme', 'dark');
```

This matters more than it looks. Both objects snapshot what the request arrived with
(`Session` captures its initial data on start, `Cookies` its received cookies in the
constructor) in order to know what changed. A per-request wrapper around the PSR
request cannot hold them: every `withHeader()` / `withAttribute()` rebuilds the
wrapper, so the snapshot would silently reset and the dirty tracking would lie. The
context is the natural owner because it survives those mutations.

Plain PSR requests keep their helpers as static functions in `Kaly\Http\RequestUtils`:

```php
use Kaly\Http\RequestUtils;

RequestUtils::getPreferredLanguage($request, ['en', 'fr']);
RequestUtils::isXhr($request);
```

## Which middlewares ran

The pipeline marks every middleware that really entered, so nothing has to register
itself:

```php
$ctx->middlewares();
// [
//     RequestIdMiddleware::class, // example name — provide your own PSR-15 middleware
//     SessionMiddleware::class,
//     AuthMiddleware::class,
// ]

$ctx->hasMiddleware(AuthMiddleware::class); // example class name
```

This is the list of middlewares that were *executed*, not the ones that were merely
registered: a middleware whose condition returned `false` is absent.

It is extremely useful for a debug toolbar, diagnostics, exceptions, tests and
understanding why a context holds a given piece of data. It is **not** a dependency
API though: to get the current user, read the accessor, never

```php
// don't
if ($ctx->hasMiddleware(AuthMiddleware::class)) { // example class name
```

## The pipeline

The context is created by the `Kernel` and travels through the whole cycle:

```text
App
 +- Kernel
     +- creates HttpContext
     +- beforeRequest($ctx)
     |
     +- INCOMING middleware
          |
          +- trusted proxy / request id / static files / global limits...
          |
          +- RoutingHandler            <- fixed kaly step
               +- match Route
               +- resolve locale
               |
               +- ROUTED middleware
                    |
                    +- auth / authorization / CSRF / route rate limit
                    |
                    +- RequestDispatcher
                         +- controller -> response

     <- exception -> response        <- kernel, whatever the origin
     +- OUTGOING middleware          <- Response -> Response, exactly once
     +- finalizeResponse
     +- complete($response)
     +- afterRequest($ctx)
     +- response
```

`RoutingHandler` is not configurable: it is what guarantees that anything registered
as routed already knows its route. `RequestDispatcher` is a plain
`RequestHandlerInterface` that no longer routes, it only goes from a resolved route to
a response — and because the route comes from the context, it can be tested with no
router at all:

```php
$ctx = new HttpContext($request);
$ctx->useRoute($route);
$ctx->useLocale('en');

$response = $dispatcher->handle($ctx->request());
```

See [middlewares](app.md#using-middlewares) for how to register into the three phases.

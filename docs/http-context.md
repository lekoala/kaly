# Http context

PSR-7 and PSR-15 give an excellent interop protocol, but not an application model.
When everything is "just a middleware", you lose the notion of phase, of dependency
and of already established context.

Kaly keeps PSR as the boundary and adds `Kaly\Http\HttpContext` as the internal model
of a request: **PSR for interop, `HttpContext` for richness, bands for order**.

```text
one request -> one context -> the whole cycle -> one response
```

## What it holds

```php
$ctx->request;      // the current PSR-7 request
$ctx->response;     // the last known response, null before dispatch
$ctx->route;        // the matched route, null before routing
$ctx->locale;       // the locale of the request, resolved during routing
$ctx->requestId;    // an optional correlation id
```

Instead of probing the request:

```php
$route = $request->getAttribute('route');
$locale = $request->getAttribute('locale');
if ($router !== null) { ... }
```

you read established state directly:

```php
$ctx->route?->module;
$ctx->locale;
```

There is deliberately no parallel system of capability flags: a typed nullable
property already says whether something happened. The module is not duplicated
either, it is available through `$ctx->route?->module`.

## Getting it

The context travels as the one and only kaly request attribute, so third party PSR-15
middlewares simply ignore it and nothing new has to be implemented:

```php
use Kaly\Http\HttpContext;

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
    return (string) $this->ctx()->locale;
}
```

> Request attributes are not meant to be used as a general application storage
> anymore. Keep a single kaly attribute, the context, and put the rest inside it.

## Mutability

The context is mutable for the duration of one request, but the PSR messages it
carries stay immutable. Assign a new message rather than mutating one:

```php
$ctx->request = $ctx->request->withAttribute('foo', 'bar');
$ctx->response = $ctx->response?->withHeader('X-Foo', 'bar');
```

This gives back the comfort of a front controller without breaking PSR-7.

The pipeline keeps `$ctx->request` and `$ctx->response` up to date on its own: every
step rebinds the current request (so a middleware handing over a brand new request
object never loses the cycle) and stores back the response it produced. Before
dispatch `$ctx->response` is `null`, after it is always a `ResponseInterface`.

## Which middlewares ran

The pipeline marks every middleware that really entered, so nothing has to register
itself:

```php
$ctx->middlewares();
// [
//     ErrorMiddleware::class,
//     RouterMiddleware::class,
//     SessionMiddleware::class,
//     AuthMiddleware::class,
// ]

$ctx->hasMiddleware(AuthMiddleware::class);
```

This is the list of middlewares that were *executed*, not the ones that were merely
registered: a middleware whose condition returned `false` is absent.

It is extremely useful for a debug toolbar, diagnostics, exceptions, tests and
understanding why a context holds a given piece of data. It is **not** a dependency
API though: to get the current user, read the typed property, never

```php
// don't
if ($ctx->hasMiddleware(AuthMiddleware::class)) {
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

     <- PSR-15 unwind
     +- afterRequest($ctx)
     +- response
```

`RoutingHandler` is not configurable: it is what guarantees that anything registered
as routed already knows its route. `RequestDispatcher` is a plain
`RequestHandlerInterface` that no longer routes, it only goes from a resolved route to
a response.

See [middlewares](app.md#using-middlewares) for how to register into the two bands.

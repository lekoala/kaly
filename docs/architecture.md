# Architecture

Kaly composes an application from modules and runs each request through fixed
bands. Two sentences carry the whole design:

```text
one request -> one context -> the whole cycle -> one response
```

## Composition

`App` owns the boot: modules declare services and routing in `config.php`,
definitions merge by priority, the kernel is built once. There is no global
route file and no service locator at runtime: controllers receive services by
constructor, actions receive url segments (plus one trailing input) by
signature. See [Modules](modules.md) and [DI](di.md).

The object graph is the application configuration. Each module's `config.php` is
its local composition root: it selects implementations and supplies the values
needed to construct them. `Env` reads deployment values, `Definitions` declares
the graph, and the container resolves and shares the resulting objects:

```text
environment / .env -> Env -> module/config.php -> Definitions -> Container -> services
```

Services receive object dependencies and constructor values rather than querying
a global configuration tree. A separate policy or options object belongs in this
graph when the values have semantics of their own. Composer makes library code
available; the application chooses how to compose it.

## Bands

```text
incoming -> routing -> routed -> [route middlewares] -> dispatcher
  -> outgoing -> commit (session, cookies) -> terminate hooks
```

- **incoming**: before routing, no route yet (`HttpContext::from()` works,
  `route()`/`locale()` throw).
- **routing**: structural, not configurable — one module owns the url.
- **routed + dispatcher**: `route()` and `locale()` guaranteed; `$ctx->url()`
  generates for the request locale.
- **outgoing**: transforms the final response (`Response -> Response`);
  `always` middlewares also cover error responses.
- **commit**: the session and cookies owned by the context are committed to
  the response, whatever produced it (a session touched during outgoing
  included).
- **terminate**: observability only, never the response.

## Ownership

What belongs to a cycle lives in `HttpContext` (route, locale, session,
cookies, response); what is shared lives in application-scoped services
(router tables compiled once, `CookiePolicy`, translator). Nothing request
scoped is static — except PHP's own `$_SESSION`, which is why concurrent
runtimes bind a `SessionProviderInterface` returning request-scoped storage.
See [Http context](http-context.md) and [Runtime](runtime.md).

## Runtime vs tests vs development

```text
Production    HTTP runtime ────────► App
Tests         TestClient ──────────► App
Development   PHP built-in server ─► App
```

Kaly owns the composition and the in-memory solicitation (`Kaly\Test`),
neither the development server nor a CLI. See [Testing](testing.md) and
[Serving](serving.md).

---
layout: default
title: Architecture
nav_order: 17
---
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

## PHP attributes

In Kaly, attributes may describe and enrich the application; explicit
declarations define its exposure, dependencies and invariants. The essential
graph stays visible, centralized and verifiable instead of scattered through
reflection metadata.

Removing an attribute may remove documentation, diagnostics, observability or
tooling. It should not invalidate a business rule, authorization rule, or
application invariant. The review question is:

> Would forgetting this attribute make the application incorrect, insecure, or violate an invariant?

If yes, the behavior belongs in explicit PHP: a route declaration, a container
definition, a method call, an interface or a type. An omitted attribute must
never silently open what it was meant to protect: forgetting an access-policy
attribute opens the action. `SensitiveParameter` reduces the exposure of
arguments in traces, but it is not a complete confidentiality guarantee: where
confidentiality is an invariant, it also requires an explicit logging and
error-handling policy. Caching is a fitting attribute when it stays an
optimization; an audit that becomes legally or functionally mandatory is no
longer mere observability and should not depend on an attribute alone.

This is why Kaly defines no attribute of its own: routes, services and HTTP
policies are declared in `config.php` instead of being discovered by
reflection. Explicit routing takes ownership of an action: once a controller
action appears in a route table, convention routing neither resolves nor
generates a url for it. See [Routing](routing.md).

### Attribute collection

Attributes can also be useful as input for build-time or offline tooling.

For example, [`composer-attribute-collector`](https://github.com/olvlvl/composer-attribute-collector)
discovers attribute targets while Composer generates the autoloader and
exposes the collected metadata later without runtime scanning. Changing an
attribute requires regenerating the autoloader to refresh the collected view.
Kaly keeps its load-bearing declarations explicit and centralized instead, so
the essential graph never depends on such a synchronization: generated
collections stay derived artifacts.

This is a good fit for documentation generation, audits, translation-key
collection, static inventories, diagnostics or other derived metadata. For
instance, translation metadata could be collected into a used/missing-keys
report without the translator ever depending on that collection to serve a
request. Likewise, an `AuditTrail` attribute feeding an inspection tool or an
optional instrumentation fits well; an audit trail that is legally mandatory
for the operation to count as performed is an application invariant and
belongs in the explicit flow.

A useful distinction is:

- **collect metadata from attributes** to derive secondary artifacts or reports;
- do not **build the application's essential runtime graph** from attributes.

```text
PHP source
   │
   ├── explicit runtime graph
   │     routes / DI / permissions / transactions
   │
   └── attributes
         ↓
      collection
         ↓
      docs / OpenAPI / i18n inventory / audits / diagnostics
```

Stale collected data may make a derived report incorrect — that is expected
and fixed by regenerating. What must never happen is application traffic
depending on that synchronization to stay correct.

## Runtime vs tests vs development

```text
Production    HTTP runtime ────────► App
Tests         TestClient ──────────► App
Development   PHP built-in server ─► App
```

Kaly owns the composition and the in-memory solicitation (`Kaly\Test`),
neither the development server nor a CLI. See [Testing](testing.md) and
[Serving](serving.md).


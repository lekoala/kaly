# http and routing

## HTTP lifecycle

Kaly's request flow is:

```text
incoming
  -> routing
  -> routed
  -> route middlewares
  -> controller dispatcher
  -> outgoing
  -> commit
```

Use the layer that matches the concern.

### Incoming middleware

Use before routing for concerns such as:

- trusted proxy handling;
- request IDs;
- public/static files;
- truly global request filtering.

### Routed middleware

Use when the route must already be known:

- authentication;
- authorization;
- CSRF;
- route-aware rate limiting.

### Route middleware

Use route/group/module middleware for behavior attached to a particular route
or module area. HTTP policy belongs to routing: an action with its own policy
deserves an explicit route.

Explicit routing takes ownership of an action: once a controller action
appears in a route table, convention routing neither resolves nor generates a
URL for it. Custom resolvers claim nothing automatically.

### Outgoing middleware

Outgoing middleware transforms the final response.

Use it for concerns such as:

- security headers;
- response compression;
- response format transformations;
- headers that depend on the produced response.

Outgoing middleware uses `Kaly\Core\Middleware\OutgoingInterface`, not a
PSR-15 request stack. `always: true` also runs during recovery after outgoing
or commit failures, so it may see two responses for one request. Keep it
idempotent and free of side effects. If it throws, Kaly reports the failure
and preserves the response it received.

Do not use `onTerminate()` as a response transformation hook.

## Middleware ordering

Inside one middleware band, lower priorities run first.

Priority is ascending, then registration order.

For example, when both public files and sensitive-path rejection are enabled,
the file server must get a chance to serve an existing file before
`PreventSensitivePathAccess` rejects sensitive paths.

Do not assume that a larger priority means "earlier".

## Routing

Every URL belongs to one module.

Prefer the simplest routing mechanism that expresses the contract:

1. explicit route table for explicitly designed routes;
2. custom resolver for runtime/domain-backed routing;
3. convention routing for conventional controller/action URLs.

Do not introduce a custom resolver merely to emulate a catch-all controller.

A resolver returning no route means "not mine"; the next resolver may run.

A method mismatch for a known route is authoritative: preserve `405` rather
than falling through and reinterpreting the URL elsewhere.

## Canonical URLs

Kaly canonicalizes only what the application configures via
`App::routing(TrailingSlash, localePrefixes)` (defaults: `Preserve`, no
prefixes). The recommended profile is `App::default()` (`Remove` with locale
prefixes); `routing(Add, true)` is the explicit legacy choice.

Depending on the configured route/module this includes behavior such as:

- trailing slashes (`Add`/`Remove`; `Preserve` never redirects);
- canonical controller/action spelling;- lowercase locale prefixes (prefix strategy only);
- locale-prefix rules (prefix strategy only);
- default-locale home normalization (prefix strategy only).

`url()` and `urlFor()` always produce the canonical form of the policy: a
generated URL matches its route directly, without a canonicalization
redirect.

`APP_LOCALES` describes i18n, never a URL topology. `localized()` means a
module participates in locale-prefix routing; translated route paths without
prefixes match by path instead. A `/{locale}/...` topology of the
application's own design reads the placeholder through the explicit
`RouteLocale` middleware, never through implicit consumption.

Do not weaken the configured canonicalization merely to preserve an old
site's URL scheme.

When migrating an existing site, keep historical URLs with explicit redirects.

## 404 and other application error pages

Do not implement a generic application 404 as the last route or a catch-all
resolver merely to render an HTML page.

Routing should still fail normally.

For production HTML error pages, bind `Kaly\Core\ErrorViewInterface` and return
a `Kaly\View\View`, or `null` to keep the standard response. Kaly's
`ViewResponder` supplies the same reserved helpers as a controller view and
preserves the original error status and headers. Explicit HTTP bodies/formats,
JSON clients and debug pages take precedence. A failing error template is
logged and falls back to the standard response without recursive rendering.

If routing failed before resolving a locale, `LocaleResolver` negotiates from
the request attribute, Accept-Language and application default; an unmatched
locale-prefixed URL alone does not set the locale.

Use `Kaly\Http\ExceptionHandlerInterface` or a decorator for broader error
conversion, such as mapping domain failures to HTTP errors.

Preserve:

- the original HTTP status;
- required response headers;
- JSON/problem responses for clients that request JSON;
- debug behavior where appropriate.

Domain errors should be translated to HTTP at the application/HTTP boundary,
not inside the domain.

## Static files and downloads

Production static files should normally be served by the web server or CDN.

Kaly's file-serving facilities are useful for development and controlled file
responses.

`FileResponseFactory` uses `ContentType::forFile()` for deterministic web MIME
types, including CSS and JavaScript on Windows. `Fs::contentType()` remains a
generic fileinfo-based filesystem helper; keep HTTP MIME policy in the HTTP
layer rather than changing that helper.

For private downloads:

1. resolve the requested domain resource;
2. perform the application's authorization check;
3. pass the already-authorized storage path to `FileResponseFactory`.

Never pass raw user-provided filesystem paths to the file response factory.

If static file serving behaves incorrectly, fix or configure the file-serving
layer rather than adding broad response middleware as a workaround.

## Assets

Use Kaly's asset facilities when the application has adopted them.

Do not assume module source assets are production-public.

Development serving and production publishing are distinct concerns.

Generate asset URLs through the provided asset helper rather than manually
constructing `/assets/...` paths when the application uses Kaly assets.

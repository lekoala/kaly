# Changelog

## Unreleased

- Auth fixes: `PermissionSet` iteration casts numeric keys back to strings so
  a rebuilt set sees the same strings; Bearer accepts the full b64token
  padding (`=*`); the `AdminGuard` recipe keeps the guard stateless and takes
  `HttpContext` at the call, since context injection only works for
  controllers. Covered by `RequestIsolationTest`: two interleaved cycles on
  one App keep their identity, CSRF secret and CSP nonce down to the rendered
  template and the outgoing header.
- Auth hardening: `authenticate()` and `login()` build permissions before
  mutating anything, so a failure leaves the previous identity untouched;
  `PermissionSet` is itself iterable over the granted strings, and `login()`
  refuses an empty identifier it could never restore. Covered by `AuthTest`,
  including Fiber isolation of request state.
- Breaking: `CsrfMiddleware` moves from `Kaly\Http\Csrf` to
  `Kaly\Core\Middleware` (`Http` cannot depend on `Core`); the `Auth` layer is
  now locked out of `Core` in the architecture guard.
- `Authorization` parsing is strict: a single header value (multiples
  rejected, never merged), token syntax for the scheme, no control characters
  in Basic credentials, b64token syntax for Bearer. An empty
  `UnauthorizedException` challenge now throws. Covered by
  `AuthorizationTest`.
- Method override lives directly under `Kaly\Http`
  (`Kaly\Http\MethodOverrideMiddleware`): middleware group by feature, not by
  pipeline role. Covered by `MethodOverrideTest`, including the
  override-then-CSRF chain.
- CSP nonce: `HttpContext::csp()` shares one lazily generated nonce per
  response between templates (reserved `csp` variable, the very same object)
  and the applicative outgoing `Content-Security-Policy` header. Documented
  on the new Security page, which also hosts the CSRF reference.

- Remove the unused `psr/simple-cache` dependency; Kaly does not require a cache.
- Request middleware bands now retain their ordered entries across Fiber
  suspensions, so registry changes cannot skip or repeat steps already in flight.
- Breaking: remove unused `Runner::getRegistry()`/`getBand()` and
  `OutgoingRunner::getRegistry()`/`add()`; configure outgoing middleware through
  `Registry::outgoing()`. Runner recursion is now private and the internal
  `RunNextHandler` class is removed, reusing the existing callable adapter.

- Add `Json::pretty()` for indented JSON with unescaped slashes and Unicode.

- Breaking: `Json::decodeMap()` and `decodeMapRelaxed()` now return
  `array<array-key, mixed>` and accept integer-string object keys, which PHP
  converts to integer keys. Objects and lists remain distinct at the JSON boundary.

- Typed translation values: `Kaly\I18n\TranslationKey` (id + domain),
  `Kaly\I18n\Translatable` (`translate(LocalizedTranslator)`) and
  `LocalizedTranslator::resolve()`. A string passed to `resolve()` is always
  a literal and never calls the engine. New
  `Kaly\I18n\TranslatableValidationException` for keyed validation errors,
  translated by the new default `Kaly\Core\LocalizedExceptionHandler` when
  the request has a locale. `ValidationException` and
  `Kaly\Http\ExceptionHandler` are unchanged.
- Breaking: the native translator now matches Symfony on the portable
  subset. A missing key returns the id instead of `{{id}}`, parameters
  replace exact placeholders only, implicit pluralization is removed,
  `Translator::getBaseDomain()`/`setBaseDomain()` are removed, ambiguous
  flat-vs-nested ids throw, and catalogs from several paths merge key by
  key. The translator file cache is removed. `NativeVsSymfonyCompatibilityTest`
  locks the portable contract.

- Breaking: debug mode no longer logs the middleware pipeline automatically on
  each request. Use an `onTerminate()` hook for explicit pipeline logging.
  Middleware tracking in `HttpContext` and its display on the debug error page
  remain available.

- Request authentication: `HttpContext::auth()` carries the request identity
  (`Kaly\Auth\Authentication` with any application principal plus a closed
  `Kaly\Auth\PermissionSet`: `allows()`/`any()`/`all()`), and
  `Kaly\Auth\SessionAuthentication` owns the session lifecycle
  (`identifier()`/`login()`/`logout()` around the `_auth` reference, with id
  rotation). Templates observe it through the reserved `auth` variable
  (`Kaly\Auth\AuthView`).
- HTTP credentials: `Kaly\Http\Authorization` parsing (Basic/Bearer),
  `UnauthorizedException` with a mandatory `WWW-Authenticate` challenge, and
  `Kaly\Auth\Middleware\BasicAccessMiddleware` as a staging gate that never
  establishes an identity.
- Breaking: `RouteScope` gains `middlewares()`, declared per module with
  `Module::middleware()` and merged in front of the route middlewares
  (claims included). External `RouteScope` implementations must return their
  scope middlewares, or `[]`.
- Breaking: `SessionInterface::regenerateId()` now throws when the rotation
  fails instead of succeeding silently.
- CSRF: `Kaly\Http\Csrf\Csrf` session primitive (one ASCII secret per
  session, masked per render), `CsrfMiddleware` (body `_csrf`, then
  `X-CSRF-Token` header), `InvalidCsrfTokenException` (403), and the reserved
  `csrf` template variable (`Kaly\Http\Csrf\CsrfView`, lazy: rendering without
  `csrf.token()` never creates the session).
- Method override: opt-in `Kaly\Http\Middleware\MethodOverrideMiddleware` in
  the incoming band tunnels POST to `PUT`/`PATCH`/`DELETE` before routing
  (`_method` form field or `X-HTTP-Method-Override` header; conflicting or
  unknown targets are a 400 `InvalidMethodOverrideException`).

## 0.1.0 - 2026-10-02

First tag: a small modular PSR HTTP framework (PSR-7 messages, PSR-11
container, PSR-15 middleware) with convention-based routing, modules and
first-class dependency injection. See UPGRADE.md when updating from an earlier
dev snapshot.

- Restrict the public `/.well-known/` exception to its first path segment;
  hidden files and traversal beneath it remain blocked.
- Reject backslashes in served file and asset paths to prevent hidden-file
  protection bypasses on Windows.

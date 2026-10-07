# Changelog
## Unreleased

### Added

- `App::default()`: the recommended Kaly application profile (`Remove` with
  locale prefixes) on top of the neutral `App::create()` primitives
  (`Preserve`, no prefixes). `url()` and `urlFor()` always produce the
  canonical form of the `TrailingSlash` policy, so a generated url matches
  its route directly without a canonicalization redirect.
- `App::routing(TrailingSlash $trailingSlash = TrailingSlash::Preserve, bool $localePrefixes = false)`.
  `Kaly\Router\TrailingSlash` (`Add`, `Remove`, `Preserve`) replaces the
  trailing-slash booleans in `Router`, `ConventionResolver` and
  `RedirectUris`; `/` never redirects and `Preserve` keeps the declared
  spelling on generation.
- `Kaly\Core\Middleware\RouteLocale` projects a `{locale}` route placeholder
  onto the request locale: supported values apply, unsupported ones are a 404,
  incoherent declarations throw `Kaly\Ex`.
- `Kaly\Http\HttpPath` (fail-closed URI inspection, never canonicalizing) and
  `Kaly\Http\SensitivePathPolicy` backing the renamed
  `Kaly\Http\Middleware\PreventSensitivePathAccess`.
- `TestClient` keeps response cookies sufficient for session testing plus
  `followRedirect()`, bounded `maxRedirects`, explicit `cookies` and
  `clearCookies()`; `Kaly\Test\MemorySessionProvider` persists sessions across
  cycles in tests. Cookie attributes (domain, path, secure, expiry) stay out
  of scope by design.
- Recipes under `docs/recipes/`: Doctrine (per-operation factory), Cycle,
  Symfony Translator, console and cron. Stateful resources stay per operation
  with explicit transactions and closing; no request-scope container is added.
- Render capability guarantees: the six `RenderVariables::SHARED` helpers
  reach kaly-tpl partials and layouts and stay isolated across concurrent
  renders; Twig `include ... only` usage is documented.

- Strict web date parsing through `Kaly\Util\Dates`: `isDate()` / `isTime()` /
  `isInstant()` validators plus throwing (`date()`, `instant()`, `at()`) and
  nullable (`tryDate()`, `tryInstant()`, `tryAt()`) factories returning
  `DateTimeImmutable`. `Y-m-d` dates resolve at midnight and `at()` combines a
  date with an `H:i` or `H:i:s` time (missing seconds default to `00`), both in the PHP default timezone unless an explicit
  one is given; `instant()` requires an RFC 3339 string with an explicit
  offset (`Z` accepted in either case, fractional seconds up to microseconds,
  no leap seconds). `isDate()` is timezone-independent while `date()`
  guarantees a real local midnight: days whose midnight is skipped by a DST
  transition are rejected rather than normalized. Invalid values
  return `null` from `try*` or throw `InvalidArgumentException`, while an
  unknown timezone string always throws `DateInvalidTimeZoneException`, even
  for an invalid value. A [Utilities](docs/utils.md) page maps the
  `Kaly\Util` / `Kaly\Clock` foundations; the PHP docblocks stay the
  reference.
  `Dates::timezone()` centralizes the `DateTimeZone|string|null` resolution
  (empty string falls back to UTC) and `SystemClock` now delegates to it.
  Richer needs stay out of scope: `brick/date-time`, `bakame/tokei` and
  `nesbot/carbon` are listed in `suggest`.

- `Types::instancesOf($value, $type)` narrows a mixed list to instances of a
  class or interface, including subclasses, preserving object identity and
  order while reindexing. Non-list inputs return an empty list.

- Application error views through `Kaly\Core\ErrorViewInterface`, rendered by
  `ViewResponder` with the same request helpers as controller views. Bind the
  interface to select templates for production HTML errors; returning `null`
  retains the standard response. Explicit bodies/formats, JSON and debug take
  precedence. Status and headers are preserved; rendering failures are logged
  and fall back without recursive error rendering.
- `Kaly\Http\ErrorPageInterface` provides the runtime-independent HTML fallback
  used by `ExceptionHandler`; Core bridges error views to it automatically.

- A consumer-facing `kaly` agent skill in `skills/kaly/`, with focused
  references for application structure, HTTP/routing, DI/runtime,
  views/i18n and auth/security. Included in Composer packages; activation
  instructions are in `README.md`.

- Request authentication:
  - `HttpContext::auth()` carries the current request identity through
    `Kaly\Auth\Authentication`.
  - Principals are application-defined objects; Kaly imposes no user interface
    or role model.
  - `Kaly\Auth\PermissionSet` provides the closed permission set
    (`allows()` / `any()` / `all()`).
  - `Kaly\Auth\SessionAuthentication` manages the `_auth` session reference,
    login/logout and session id rotation.
  - Templates observe the current identity through the reserved `auth` variable
    (`Kaly\Auth\AuthView`).

- HTTP authentication primitives:
  - `Kaly\Http\Authorization` parses Basic and Bearer credentials.
  - `UnauthorizedException` represents a 401 response with a mandatory
    `WWW-Authenticate` challenge.
  - `Kaly\Auth\Middleware\BasicAccessMiddleware` provides a simple HTTP Basic
    gate, intended for uses such as staging protection, without establishing an
    application identity.

- CSRF protection:
  - `Kaly\Http\Csrf\Csrf` provides one session-bound secret, masked differently
    on every render.
  - `Kaly\Core\Middleware\CsrfMiddleware` validates `_csrf` form values or the
    `X-CSRF-Token` header.
  - Invalid tokens result in `InvalidCsrfTokenException` (403).
  - Templates receive the reserved lazy `csrf` variable through
    `Kaly\Http\Csrf\CsrfView`; rendering a view does not create a session until
    `csrf.token()` is actually used.

- CSP nonce support:
  - `HttpContext::csp()` owns one lazily generated nonce per request.
  - Templates receive the exact same object through the reserved `csp` variable,
    allowing the nonce used in markup to match an applicative outgoing
    `Content-Security-Policy` header.
  - CSP and CSRF guidance now lives in the Security documentation.

- Opt-in HTTP method override through
  `Kaly\Http\Middleware\MethodOverrideMiddleware`. POST requests may tunnel to
  `PUT`, `PATCH` or `DELETE` before routing, using either the `_method` form
  field or `X-HTTP-Method-Override`. Conflicting or invalid overrides result in
  `InvalidMethodOverrideException` (400).

- `Json::pretty()` for indented JSON with unescaped slashes and Unicode.

- Typed translation values:
  - `Kaly\I18n\TranslationKey` for an id + domain pair.
  - `Kaly\I18n\Translatable`.
  - `LocalizedTranslator::resolve()`.

- Structured validation:
  - `Kaly\Validation` with `Violation`, `ValidationResult`, `Validator`
    (`notBlank`, `email`, `length`/`minLength`/`maxLength`, `between`,
    `oneOf`, `matches`, `count`, `add`) and `HasValidationResult`.
  - `Kaly\Http\Input\InputResult` from `InputMapper::mapResult()`: submitted
    values, the DTO when it could be built, and the collected result.
  - `problem+json` carries an `errors: [{field, code, message}]` list for
    input (400) and validation (422) failures, translated per violation by
    `Kaly\Core\LocalizedExceptionHandler` when the request locale is
    available.

- Documentation: [Building a Kaly application](docs/application-structure.md)
  describes the recommended module layout, a Mago Guard profile for the layer
  boundaries, the three integration seams, and the domain-error to HTTP mapping.

- `Kaly\Http\ContentType::forFile()` resolves the Content-Type of a served file
  deterministically for web extensions (`.css`, `.js`, ...), falling back to
  fileinfo for the rest.

- `Kaly\Core\Paths` is registered in the container, so infrastructure services
  can take it by constructor instead of a hand-built path.

### Changed

- **Breaking:** the default routing policy is `Preserve` without locale
  prefixes: no slash redirect unless configured, and `APP_LOCALES` alone never
  consumes a url segment. Use `routing(TrailingSlash::Add, true)` to keep the
  previous urls; see `UPGRADE.md`.
- **Breaking:** `PreventFileAccess` is renamed to
  `Kaly\Http\Middleware\PreventSensitivePathAccess` and no longer rejects
  dotted application routes (`/sitemap.xml`, `/robots.txt`); sensitive paths
  are rejected fail-closed instead. Update `incoming()` registrations.
- **Breaking:** `localized()` means participation in locale-prefix routing and
  requires `routing(localePrefixes: true)`; route tables with one path per
  locale are allowed without it when their paths distinguish the languages.
  `TableResolver` reports the matched entry locale without prefixes, and
  `RoutePath::join()` preserves a declared trailing slash.
- `Remove` never redirects `/`, and a mount named after a locale is allowed
  when prefixes are disabled.

- **Breaking:** `Kaly\Router\Middleware` (`#[Middleware]`) is removed. HTTP
  policy belongs to routing: declare middlewares with `->middleware()` on a
  route, a group or a module (`$module->middleware(...)`) instead of
   attributes on controllers. `Kaly\Router\RouteMiddlewares` is removed;
  explicit middleware merging and validation moved to `Routes`.
  Replace controller attributes with route declarations; an action with its
  own policy deserves an explicit route.
- **Breaking:** explicit routing takes ownership of an action. Once a
  controller action appears in a route table, convention routing neither
  resolves nor generates a url for it (404 on the conventional path,
  `urlFor()` fails and points at the route name). Custom resolvers claim
  nothing automatically. See `UPGRADE.md`.

- **Breaking:** `RequestDispatcher` now receives `ViewResponder` instead of
  translator, renderer, assets and CSRF dependencies. Update manual construction;
  normal application autowiring needs no changes. Before routing has established
  a locale, error views use `LocaleResolver` negotiation; an unmatched URL prefix
  alone does not establish a locale.

- **Breaking:** `KalyTplRenderer` requires kaly-tpl 0.2 and shares the reserved
  `i18n`, `url`, `asset`, `auth`, `csrf` and `csp` helpers across layouts and
  partials for each render. Remove explicit forwarding of these helpers from
  templates and engine globals to avoid shared-data collisions.
  `RequestDispatcher::VAR_*` constants are replaced by
  `Kaly\View\RenderVariables` constants; see `UPGRADE.md`.

- **Breaking:** validation is structured. `ValidatableInput::validate()` takes
  a `Validator` and collects violations, request input constructors never
  validate, `InputMapper::mapResult()` accumulates mapping errors into a typed
  `InputResult<T>`, and the `final` `InputException`/`ValidationException`
  (sharing the `internal` `ValidationResultException` base) are built from a
  `ValidationResult` with an `errors` list in `problem+json`. An empty result
  can no longer become an exception (`LogicException`). Misconfigured rules
  throw even for `null` values, and one-sided `between()` violations use the
  `between_min` / `between_max` codes.
  `Kaly\I18n\TranslatableValidationException` is removed; see `UPGRADE.md`.

- **Breaking:** `RouteScope` gains `middlewares()`. Modules declare scope-wide
  middleware with `Module::middleware()`, and those middleware are merged ahead
  of route/group/controller/action middleware, including for claims. External
  `RouteScope` implementations must return their middleware list or `[]`.

- **Breaking:** pure middleware classes now live under their domain's
  `Middleware` namespace:
  - `Kaly\Http\Middleware\MethodOverrideMiddleware`
  - `Kaly\Http\Middleware\FileServer`
  - `Kaly\Http\Middleware\PreventFileAccess`
  - `Kaly\Asset\Middleware\AssetServer`

  Middleware that requires `HttpContext` or other Core lifecycle state belongs
  to `Kaly\Core\Middleware`, such as `CsrfMiddleware`.

- **Breaking:** the session cookie is now host-only by default.
  `SessionCookie::deriveOptions()` no longer infers `Domain` from the request
  host. Applications that intentionally share a session across subdomains must
  configure the domain explicitly through `CookiePolicy(domain: ...)` or the
  session provider options.

- **Breaking:** `SessionInterface::regenerateId()` must now either rotate the
  session id successfully or throw; native sessions no longer silently ignore
  a failed `session_regenerate_id()`.

- **Breaking:** `BasicAccessMiddleware` rejects an empty username or password at
  construction. An empty realm remains valid, and realm values are emitted as
  properly escaped HTTP quoted strings.

- **Breaking:** `BasicAccessMiddleware` rejects a realm containing characters that
  cannot appear in an HTTP header. A realm that would previously have produced an
  invalid `WWW-Authenticate` header now fails at construction. An empty realm and
  a realm containing HTAB remain valid.

- **Breaking:** `BasicAccessMiddleware` compares the username and the password on
  every request instead of short-circuiting, so a valid username is no longer
  distinguishable from an invalid one by response time.

- `Authorization` parsing is stricter:
  - multiple `Authorization` header values are rejected rather than merged;
  - authentication schemes must use valid token syntax;
  - Basic credentials reject control characters;
  - Bearer credentials follow the complete `b64token` grammar, including
    optional `=` padding.

- **Breaking:** `PermissionSet` rejects the empty permission, in a set as well as
  in `allows()` / `any()` / `all()`. An empty string, or a string-backed enum
  whose value is empty, throws `InvalidArgumentException` instead of granting a
  permission that matches nothing in particular and `''` in particular.

- `PermissionSet` preserves granted permission strings when iterated and rebuilt,
  including strings that PHP would otherwise coerce to integer array keys.

- Authentication state changes are atomic: permissions are built before
  `Authentication::authenticate()` or `SessionAuthentication::login()` mutate
  existing request/session state. `login()` also rejects an empty identifier,
  which could not later be restored.

- The documented `AdminGuard` pattern is stateless: the current `HttpContext` is
  supplied when checking access rather than constructor-injected, since request
  context injection is reserved for controllers.

- Browser flows protected by HTTP Basic are now explicitly documented as
  CSRF-sensitive: browsers may replay Basic credentials automatically. Explicit
  non-browser Bearer/API clients remain outside that threat model.

- **Breaking:** `Json::decodeMap()` and `decodeMapRelaxed()` now return
  `array<array-key, mixed>` and accept integer-string object keys, which PHP
  converts to integer keys. JSON objects and lists remain distinct.

- **Breaking:** the native translator now matches Symfony's portable subset:
  - missing keys return the id instead of `{{id}}`;
  - parameter replacement matches exact placeholders only;
  - implicit pluralization is removed;
  - `Translator::getBaseDomain()` and `setBaseDomain()` are removed;
  - ambiguous flat-vs-nested translation ids throw;
  - catalogs from multiple paths merge key by key;
  - the translator file cache is removed.

- Request middleware runners now retain their ordered entries across Fiber
  suspensions, so registry changes cannot skip or repeat middleware already in
  flight.

### Fixed

- The localized home generates `/` for the default locale even when declared
  as an explicit `/` route, and the default-locale prefix redirect targets
  `/` instead of an empty `Location` under `Remove`.
- Translated route tables sharing a match pattern without locale prefixes now
  fail at compile time instead of letting generation reach the wrong handler;
  with prefixes they stay disjoint as before.
- `TestClient` replays the parsed body on 307/308 redirects, so form data
  survives `followRedirect()` like the raw body already did.
- `TestClient` resolves relative redirect locations per RFC 3986 (dot-segment
  removal) and refuses anything off-origin: network-path references, scheme
  and effective-port changes, not just host changes.

- Static files are served with a correct Content-Type on every platform: CSS
  and JS no longer fall back to `text/plain` when fileinfo does (notably on
  Windows).

- The demo registers `FileServer` before `PreventSensitivePathAccess`, so `/app.css` is
  served instead of being rejected by the routing guard.

- Request-scoped identity, CSRF state and CSP nonce remain isolated between
  interleaved request cycles on the same `App`, including Fiber suspensions.

- Bearer parsing now accepts valid `b64token` padding.

- Rebuilding a `PermissionSet` from its iterator no longer changes numeric-looking
  permission strings.

### Removed

- **Breaking:** remove unused `Runner::getRegistry()` / `getBand()` and
  `OutgoingRunner::getRegistry()` / `add()`. Configure outgoing middleware
  through `Registry::outgoing()` instead.

- The internal `RunNextHandler` is removed; runner recursion is private and
  reuses the existing callable adapter.

- **Breaking:** debug mode no longer logs the middleware pipeline automatically
  for every request. Middleware tracking remains available through
  `HttpContext` and on the debug error page; applications can add explicit
  pipeline logging through `onTerminate()`.

- Remove the unused `psr/simple-cache` dependency. Kaly does not require a cache.

## 0.1.0 - 2026-10-02

First tag: a small modular PSR HTTP framework (PSR-7 messages, PSR-11
container, PSR-15 middleware) with convention-based routing, modules and
first-class dependency injection. See UPGRADE.md when updating from an earlier
dev snapshot.

- Restrict the public `/.well-known/` exception to its first path segment;
  hidden files and traversal beneath it remain blocked.
- Reject backslashes in served file and asset paths to prevent hidden-file
  protection bypasses on Windows.

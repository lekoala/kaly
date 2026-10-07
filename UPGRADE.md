# Upgrade guide

Kaly is `0.x`: breaking changes are made deliberately, in favor of a smaller and
sharper API, rather than piled up behind aliases. Each section lists what changed and
how to migrate.

## Explicit routing policy: trailing slash and locale prefixes

- New `App::routing(TrailingSlash $trailingSlash = TrailingSlash::Preserve, bool $localePrefixes = false)`.
  The defaults canonicalize nothing: both `/foo` and `/foo/` match with no
  redirect, and `APP_LOCALES` never consumes a url segment by itself.
- To keep the previous urls, opt in explicitly before boot:

```php
use Kaly\Router\TrailingSlash;

$app->routing(TrailingSlash::Add, true);
```

- `Kaly\Router\Router`, `ConventionResolver` and `RedirectUris` take
  `TrailingSlash` instead of `bool $forceTrailingSlash`; `Router` also takes
  `bool $localePrefixes`. Update manual constructions (or let `App` build the
  router).
- `localized()` now means participation in locale-prefix routing and requires
  `routing(localePrefixes: true)`; without prefixes it fails at boot. Route
  tables with one path per locale stay valid without `localized()` when their
  full paths distinguish the languages, and a mount may be named after a
  locale. An application-designed `/{locale}/...` topology reads the
  placeholder through the new `Kaly\Core\Middleware\RouteLocale` middleware.
- `PreventFileAccess` is renamed to
  `Kaly\Http\Middleware\PreventSensitivePathAccess`: dotted application routes
  (`/sitemap.xml`, `/robots.txt`) now reach their handler, while sensitive
  names (`.env`, `.git`, Composer files, executable source extensions) and
  ambiguous encodings are rejected fail-closed. Update `incoming()` registrations.

## Correct Accept-Language negotiation

- New `Kaly\Http\AcceptLanguage` replaces `RequestUtils::parseAcceptedLanguages()`
  (removed): it parses `; q=0.5` and `;Q=0.5` properly, keeps the wildcard and
  honors `q=0` as a refusal. `RequestUtils::getPreferredLanguage()` keeps its
  signature and delegates, so `LocaleResolver` needs no change.
- Before, `fr;q=0` could still be selected when it was the only allowed locale;
  it now falls back to the default. If you relied on the raw
  `array<string,float>` of `parseAcceptedLanguages()`, use
  `AcceptLanguage::fromRequest($request)` and read the entries instead.

## Removed classes

- `Kaly\Http\Exception\ResponseException`: shaped responses belong to
  controller results (`View`, `JsonResult`), not to exceptions. It had no
  consumer in the framework; a controller returning a response body should
  return that body or a result object instead of throwing.

## Route middleware is explicit and tables own their actions

- `#[Kaly\Router\Middleware]` on controllers and actions is removed, with the
  reflection, class-lineage order, deduplication cache and `RouteMiddlewares`
  class behind it. HTTP policy belongs to routing: a route exposes a controller
  **and** declares the middlewares required to reach it.
- Before (attribute on the controller):

```php
#[Middleware(StaffOnly::class)]
abstract class AdminController extends AbstractController {}
```

After (policy on the routes):

```php
$routes
    ->prefix('/admin')
    ->middleware(StaffOnly::class)
    ->group(function (Routes $routes): void {
        $routes->get('/orders', [OrderController::class, 'index']);
    });
```

Use `$module->middleware(...)` for a policy covering a whole area (tables,
claims and convention); give an action with its own policy an explicit route.
- Explicit routing takes ownership of an action: once a controller action
  appears in a route table, convention routing neither resolves (404 on the
  conventional url, whatever the HTTP method) nor generates
  (`Router::urlFor()` throws and points at the route name) for it. Ownership
  is case-insensitive and refuses the conventional url outright, without
  falling back to another action. This closes
  the double exposure where a protected table url stayed reachable through an
  unprotected conventional one. Custom resolvers claim nothing automatically: a
  dynamic resolver changing reachability or policy belongs in a module without
  convention routing, or relies on module-level middleware.
- Attributes remain appropriate for metadata and optional cross-cutting
  behavior, never for application correctness: removing an attribute may remove
  documentation, diagnostics, observability or tooling, but must not invalidate
  a business rule, authorization rule, or application invariant. See
  [Architecture](docs/architecture.md).

## Controller and error views share a responder

- `RequestDispatcher` takes `(Injector, ViewResponder, ResponseFactoryInterface,
  StreamFactoryInterface, ?InputMapperInterface)`; move its former translator,
  renderer, assets and CSRF arguments to `ViewResponder`. Application autowiring
  handles this automatically.
- Bind `Kaly\Core\ErrorViewInterface` to choose production error templates instead
  of catch-all routes or a response-body decorator. Return a `View` or `null`.
  Kaly preserves the error status and headers and supplies all reserved helpers.
  JSON, debug and explicitly supplied HTTP bodies/formats retain precedence.
- Before routing resolves a locale, error views negotiate with `LocaleResolver`
  (request attribute, Accept-Language, default). An unmatched locale-prefixed URL
  does not by itself establish the error page's locale.

## kaly-tpl 0.2 and reserved render variables

- Update `lekoala/kaly-tpl` to `^0.2` when using `KalyTplRenderer`.
- The adapter shares `i18n`, `url`, `asset`, `auth`, `csrf` and `csp` across the
  page, layouts, includes and `each()` templates for the current render. Remove
  these names from explicit layout/partial data and engine globals: they now
  collide with shared data. Continue passing page-specific values explicitly.
- Replace `RequestDispatcher::VAR_I18N`, `VAR_URL`, `VAR_ASSET`, `VAR_AUTH`,
  `VAR_CSRF` and `VAR_CSP` with `Kaly\View\RenderVariables::I18N`, `URL`,
  `ASSET`, `AUTH`, `CSRF` and `CSP`, respectively.

## Structured validation replaces stringly errors

- New `Kaly\Validation`: `Violation` (`field`, `code`, `messageId`, `fallback`,
  `parameters`, `domain`), `ValidationResult` (`isValid()`, `violations()`,
  `for()`), `Validator` (`notBlank`, `email`, `length`/`minLength`/`maxLength`,
  `between`, `oneOf`, `matches`, `count`, plus `add()` for application rules)
  and `HasValidationResult`. Every rule ignores `null` (nullability belongs to
  the PHP type); a misconfigured rule (invalid pattern, `min > max`) throws
  `InvalidArgumentException`.
- `ValidatableInput::validate()` now takes the validator
  (`validate(Validator $validator): void`) and collects violations instead of
  throwing. Constructors of request inputs never validate: move every
  user-facing check from the constructor (or from a value object built by the
  mapper) into `validate()`. The mapper does not convert constructor failures
  into user errors.
- `InputMapper::mapResult()` is the non-throwing path: it accumulates mapping
  violations (400 catalog, domain `input`) and returns an `InputResult` with
  the submitted `values()`, the DTO when it could be built, and the
  `validation()`. The result carries the DTO type (`InputResult<T>`), so
  `require()` needs no `instanceof` afterwards. `map()` is now
  `mapResult(...)->require()`. The query/body conflict no longer throws: it
  keeps the query value and becomes a `conflicting_values` violation, even for
  a key the input ignores — duplicates compare as text (arrays element by
  element, keys and order kept), never silently. The private
  `merged()`/`coerce()`/`to*()` internals accumulate violations instead of
  throwing `InputException`; customize the mapping through
  `InputMapperInterface`, not by extending the `final` mapper.
- `InputException` (400) and `ValidationException` (422) are built from a
  `ValidationResult` and expose it through `HasValidationResult`; their public
  body is the first violation message. Only these HTTP failures expose their
  errors: any other exception carrying a result stays a silent 500. Both
  classes are `final` so the localized handler always rebuilds the exact type
  with its status and headers, and both share the `internal`
  `ValidationResultException` base (catch `HasValidationResult` instead).
  Failing with an empty result throws `LogicException`: an empty result is
  valid, turning it into an exception is not. Both constructors changed: pass
  a result instead of a string.
- A misconfigured `Validator` rule (missing bounds, `min > max`, invalid
  pattern) throws even when the checked value is `null`: the failure no longer
  waits for the first filled submission. One-sided `between()` violations carry
  the `between_min` / `between_max` codes (the range case stays `between`).
- JSON errors are a list: `problem+json` carries
  `errors: [{field, code, message}]` (`field: null` for global errors) with no
  `detail` when errors exist. `messageId`, `domain` and `parameters` are never
  exposed. `LocalizedExceptionHandler` translates each violation with the
  request locale, falling back to the violation fallback when the catalog has
  nothing; without a locale the fallbacks are used as is.
- `Kaly\I18n\TranslatableValidationException` is removed without an alias: a
  single global or field violation (possibly with an application domain)
  replaces the typed-exception channel. The generic `Translatable` path for
  other HTTP errors is unchanged.

## Session cookie scope and Basic credentials

- The session cookie is host-only by default: `SessionCookie::deriveOptions()`
  no longer infers `Domain` from the request host, so the cookie is never
  shared with subdomains unless configured. An application that relied on the
  previous subdomain-wide cookie must set it explicitly, through
  `CookiePolicy(domain: '.example.test')` or the provider `domain` option.
  Omitting `Domain` is the OWASP-recommended default and lets a browser reject
  the cookie on `uploads.example.test` and other sibling hosts.
- `BasicAccessMiddleware` refuses an empty username or password at
  construction (`InvalidArgumentException`) instead of accepting
  `Authorization: Basic Og==`. A missing environment variable now fails at
  boot rather than leaving the gate practically open. Pass the configured
  credentials; an empty `realm` stays valid (it is a present but undescriptive
  realm, not a weakness).

Authenticated responses carry no automatic `Cache-Control`. Add an outgoing
`no-store` middleware for private traffic; see [Security](docs/security.md)
for the recipe, including the logout answer.

## Middleware namespaces

Middlewares group by runtime dependence, not by pipeline role: a middleware
independent of the Kaly runtime lives with its domain under `*\Middleware`,
while one that requires `HttpContext` or other Core lifecycle state belongs
to `Kaly\Core\Middleware` (the perimeter guard already forbids `Http` from
depending on `Core`, so a pure middleware cannot reach for the context).

- `Kaly\Http\MethodOverrideMiddleware` → `Kaly\Http\Middleware\MethodOverrideMiddleware`.
- `Kaly\Http\FileServer` → `Kaly\Http\Middleware\FileServer`.
- `Kaly\Http\PreventFileAccess` → `Kaly\Http\Middleware\PreventFileAccess`.
- `Kaly\Asset\AssetServer` → `Kaly\Asset\Middleware\AssetServer`.
- `Kaly\Core\Middleware\CsrfMiddleware` and
  `Kaly\Auth\Middleware\BasicAccessMiddleware` are unchanged: the former reads
  the session through `HttpContext`, the latter stays with its feature.

Update the imports; constructors and behaviors are unchanged.

## Auth, CSRF and method override

- `RouteScope` has a new mandatory `middlewares()` method, declared per module
  with `Module::middleware(...)` and merged in front of the route middlewares.
  An external `RouteScope` implementation without scope middlewares returns
  `[]`; one with middlewares returns them outermost first.
- `SessionInterface::regenerateId()` now throws when the id rotation fails
  instead of succeeding silently. Code that called it defensively in a
  try/catch keeps working; code that assumed it always succeeded now gets the
  failure it was missing, typically surfacing as a 500 on login/logout when
  the backend is unusable.
- `CsrfMiddleware` moved from `Kaly\Http\Csrf` to `Kaly\Core\Middleware`: the
  integration middleware reads the session through `HttpContext`, which the
  `Http` layer cannot depend on. Update the import; the constructor and the
  behavior are unchanged.
- `SessionAuthentication::login()` refuses an empty identifier instead of
  authenticating a request it could never restore. Pass a non-empty
  identifier; `identifier()` already returned `null` for one.
- `Authorization::from()` returns `null` for multiple header values instead of
  merging them, and `basic()`/`bearer()` return `null` for syntactically
  invalid credentials (control characters, non-b64token Bearer). Code that
  validated those shapes itself keeps working; code that relied on the merged
  value must read a single header.
- `UnauthorizedException` rejects an empty challenge instead of emitting an
  empty `WWW-Authenticate` header. Always pass a real challenge.
- `BasicAccessMiddleware` now refuses a realm containing characters that cannot
  appear in an HTTP header, at construction instead of when the 401 response is
  built. An empty realm and a realm containing HTAB remain valid.
- `PermissionSet` rejects an empty permission, both when a set is built and when
  `allows()` / `any()` / `all()` is asked about one — an empty permission used
  to be granted and to match. An empty string, or a string-backed enum whose
  value is empty, now throws `InvalidArgumentException`. Pass a real permission
  name.

## Honest portable translation subset

The native translator now matches Symfony on everything it declares portable,
and no longer imitates what it cannot reproduce:

- A missing key returns the id itself, formatted with the parameters, instead
  of `{{id}}`. Search for `{{` in templates and tests.
- The bare parameter shorthand is gone: parameters replace exact placeholders
  only (`['{name}' => …]`, `['%name%' => …]`). A bare `['name' => …]` bag is
  passed to `strtr()` as is, which also replaces inside delimiters.
- Implicit pluralization is removed from the native translator: `%count%` is
  an ordinary parameter and pipes pass through untouched. Use the Symfony
  engine for pluralization and ICU messages.
- `Translator::getBaseDomain()` and `setBaseDomain()` are removed; a null
  domain always means the `messages` default.
- A dotted id produced twice from different shapes in one file (flat key plus
  equivalent nested path) now throws instead of picking one reading. Keep a
  single shape per id.
- Catalogs from several paths merge key by key with later paths winning; a
  later path no longer wipes the other messages of the domain.
- The translator file cache is removed: `getCacheDir()`, `setCacheDir()` and
  `clearCache()` are gone, and `App::boot()` no longer configures a cache
  directory. Catalogs are built lazily in memory; PHP files are the cache.

## Typed translation values

- New `Kaly\I18n\TranslationKey` (`id()` plus `domain()`, `null` for the
  default domain), `Kaly\I18n\Translatable`
  (`translate(LocalizedTranslator)`) and
  `LocalizedTranslator::resolve(string|TranslationKey|Translatable)`. A
  string passed to `resolve()` is always a literal and never calls the
  engine; when a value implements both interfaces, `Translatable` wins.
- New `Kaly\I18n\TranslatableValidationException`: the typed counterpart of
  `ValidationException`, carrying a `TranslationKey` plus parameters
  (`translation()` and `parameters()`). `ValidationException` itself is
  unchanged: a literal message is never translated.
- `ExceptionHandlerInterface` now resolves to the new
  `Kaly\Core\LocalizedExceptionHandler`, which translates an already public
  `Translatable` HTTP error with the locale of the request and keeps
  `getResponseBody()` otherwise. A `Translatable` that is not an HTTP
  exception stays a 500. `Kaly\Http\ExceptionHandler` is unchanged: rebind
  the interface to it in a `configure()` hook to keep historical bodies.
  Translation lives in Core because the Http layer never depends on I18n
  (see `mago.toml`).

## Explicit root module

- The root module is no longer inferred from the `App` namespace: a module owns
  `/` only when it mounts it. Add `$module->mount('/')` to its `config.php`.
  Without it, an application that used to answer on `/` now returns 404 there.
- This is the only break in this series that fails silently: the boot succeeds
  (a folder without `config.php`, or a module without `mount('/')`, is simply
  not the root), and the 404 names the known modules to point at the missing
  declaration. Two modules mounting `/` fail at boot instead.

## Frozen route table and one-shot boot

- `RouteCollection::definitions()` is removed. The compiled table copies every
  `RouteDefinition` on construction and never exposes its own state: mutating
  the handle you declared with (`$routes->get(...)`) after the table compiled
  no longer changes matching or url generation. `RouteCollection` and its
  `entries()` are `@internal`; `byName()` returns a copy.
- `App` boots once. A boot that throws leaves that instance unusable: a later
  `boot()` throws "App boot previously failed; create a new App instance", and
  `isBooted()` stays false. A boot runs user code with side effects (autoloader
  registration, external calls) that cannot be rolled back, so a failed boot is
  terminal: create a new `App` to retry.

## The HTTP cycle commits after the outgoing phase

- `HttpContext::commit($response)` is back. The kernel runs the outgoing phase
  first, then commits the state the cycle established: the session storage and
  its cookie, plus the cookie changes. A session read or written by an outgoing
  middleware is persisted like any other, and a response an outgoing middleware
  replaced still carries the cookies.
- `SessionProviderInterface` is back to a single
  `commit($session, $request, $response): ResponseInterface`. The
  `persist()`/`applyToResponse()` split introduced while stabilizing the cycle
  is removed: update custom providers.
- If the final commit fails, the error response runs through the `always`
  outgoing middlewares, and the commit is never retried. An `always`
  middleware can therefore run more than once for one request: it must be
  side-effect free.
- `ExceptionHandler` no longer reads `Exception::$code` as a status for a
  non-HTTP exception: only an `HttpExceptionInterface` carries a status, any
  other failure is a 500.

## The request cycle lives in Core

The package boundaries now follow the layer: Http is the protocol, Router is
urls, Core is the request cycle. `Kaly\Middleware` no longer exists — the
machinery belongs to Core and the built-in middlewares moved to their domains.
No BC aliases are kept: update every usage in one pass.

- `Kaly\Http\HttpContext` moves to `Kaly\Core\HttpContext`.
- `Kaly\Router\RoutingHandler` and `Kaly\Router\RequestDispatcher` move to
  `Kaly\Core`. The router matches and generates urls; dispatching and the
  pipeline are the cycle's business.
- The middleware machinery moves to `Kaly\Core\Middleware` and shortens:
  `MiddlewareBand` → `Band`, `MiddlewareEntry` → `Entry`,
  `MiddlewareRegistry` → `Registry`, `MiddlewareRunner` → `Runner`,
  `RouteMiddlewareRunner` → `RouteRunner`,
  `OutgoingMiddlewareInterface` → `OutgoingInterface`, `OutgoingRunner`,
  `ClosureOutgoing`, `CallableToHandlerAdapter`, `MiddlewareToHandlerAdapter`,
  `NullHandler` and `RunNextHandler` keep their names under the new namespace.
- The built-in middlewares move to their domains: `AssetServer` →
  `Kaly\Asset\AssetServer`, `FileServer` → `Kaly\Http\FileServer`,
  `PreventFileAccess` → `Kaly\Http\PreventFileAccess`.
- `Kaly\Middleware\PredefinedResponseHandler` moves to `Kaly\Test`.
- `Kaly\Core\Ex` moves to `Kaly\Ex`: the framework exception is a leaf, every
  package may throw it.
- `Kaly\Http` groups its sub-domains: `Kaly\Http\Session\*` (session
  interfaces, `SessionCookie`, providers and storages), `Kaly\Http\Cookie\*`
  (`CookiePolicy`, `Cookies`, `SetCookieHeader`), `Kaly\Http\Input\*`
  (`RequestInput`, `InputMapper`, `InputException`, `ValidatableInput`,
  `ValidationException`) and `Kaly\Http\Exception\*` (`HttpException`,
  `HttpExceptionInterface`, `NotFound`, `Forbidden`, `MethodNotAllowed`,
  `Redirect`, `Response` exceptions).
- `Kaly\Http\JsonResponse` is renamed `Kaly\Http\JsonResult`: it is a
  controller result converted by the dispatcher, not a PSR-7 response.
- `ExceptionHandler::wantsJson()` moves to `Accept::prefersJson()` — content
  negotiation lives on `Accept`.
- `ExceptionHandler` no longer renders the debug page itself: it takes an
  optional `DebugPageInterface` (Http), implemented by `Kaly\Core\DebugPage`
  and bound by default. Debug without a bound page falls back to a plain text
  trace.
- `LocaleResolver::apply()` is removed (`resolve()` + `useLocale()` cover it).
- `RedirectException::NOT_MODIFIED` is removed: 304 is not a redirect and
  never belonged there.
- `Router` no longer knows `Module`: it takes `list<RouteScope>` — any object
  answering mount/locale/resolvers/claims can be registered. `Module`
  implements it. `Module::resolvers()` entries hold `RoutesDeclaration`
  objects instead of raw closures, and claims too.
- `RouteDraft` and `PendingRoute` are removed: `RouteDefinition` is the single
  declaration shape, mutable and fluent while the table is declared —
  `$routes->get(...)` returns it directly (`->name()`, `->where()`,
  `->middleware()`, `->default()`, `->priority()`).
  `Routes::addDefinition()` no longer round-trips through a draft.
- `RouteParamCoercer` folds into `ActionSignature::coerce()`, the single home
  of action-signature introspection.
- `ActionSignature` owns the rules every route source shares: action
  admissibility (public, non-static, non-magic except `__invoke`) and the
  trailing `RequestInput` placement. Convention routing now refuses static
  methods like declared routes always did, and a misplaced `RequestInput`
  fails at table compilation instead of dispatch.
- `AssetPublisher::prune(keep: 3)` removes old versioned directories after a
  publish; the live `.version` is never removed.
- `FileServer` never serves dotfiles (`/.env`, `/.git/...`), except the
  `/.well-known/` convention.

## Pre-1.0 session, App and pipeline cleanup

No BC aliases are kept: update every usage in one pass.

- `Kaly\Http\Session` is removed. Use `NativePhpSession` (sequential runtimes)
  or a request-scoped `SessionInterface` through a `SessionProviderInterface`,
  with `NativePhpSessionProvider` (default) and `ArraySessionProvider` (tests,
  isolated cycles) provided.
- `SessionFactoryInterface` is replaced by `SessionProviderInterface`:
  `create($request)` builds the session, `commit($session, $request, $response)`
  persists it and emits the `Set-Cookie` header. Bind your own provider for
  concurrent runtimes. `NativePhpSessionFactory` is removed.
- `HttpContext` no longer creates a session provider implicitly. `session()`
  throws "no session provider is configured" unless a provider was injected;
  `App` binds `NativePhpSessionProvider` by default. A session imposed with
  `useSession()` is only persisted when a provider is present.
- `SessionInterface` is an applicative contract only
  (`get/set/has/remove/clear/pull/all` + `regenerateId/destroy`): no PSR-7, no
  session id, no cookie params. The transport read-model is a capability:
  `CookieSessionInterface` (`getId/setId/getName`, `isDestroyed`, `close`,
  `getCookieParams`), implemented by `NativePhpSession` and `ArraySession`.
  Providers only ever speak to that interface — never to a concrete session —
  and delegate the shared rules to `SessionCookie` (incoming id lookup,
  option derivation, `Set-Cookie` emission). `commitToResponse()` is removed
  from the sessions; `isActive`/`discard` remain concrete details.
  `regenerateId()` starts the session if needed before rotating, and
  `destroy()` expires the client cookie even on a session that was never
  started, so logout is effective on the very first call.
- `Cookies::SAMESITE_MODES` and `NativePhpSession::SAMESITE_MODES` are removed:
  `CookiePolicy::SAMESITE_MODES` is the single list.
- The request only scopes the cookie (`secure` on https, `domain` from the
  host) when neither the provider options nor the bound `CookiePolicy` set the
  value: an explicit policy is never downgraded by the request.
- `ArrayDataInterface` is removed. `Cookies` keeps its own string-based
  contract (`get/set/has/remove/clear/all` + change tracking + `addToResponse`).
- `CookiePolicy::default()/setDefault()` are removed, as are
  `NativePhpSession::configureDefaults()/configureExtra()/getExtraConfig()`.
  One `CookiePolicy` instance is bound per `App` (historical baseline:
  browser-session lifetime, httponly, Lax — override it in `configure()`).
  `Cookies` and the session providers take it by injection; `new Cookies($request)`
  and `new NativePhpSession([], $request)` become
  `new Cookies($request, $policy)` and `(new NativePhpSessionProvider($options, $policy))->create($request)`.
  `session_name()`/`session_save_path()` for native sessions move to provider
  options (`name`, `save_path`).
- `App::get()`, `getInjector()`, `getLogger()`, `getDebugLogger()` and
  `respond()` are removed. `App::container()` is the explicit escape hatch for
  tests and integration (`$app->container()->get(Foo::class)`),
  `App::kernel()` stays for the pipeline (both are renamed below).
- Controllers receive the cycle by **type**, not by parameter name:
  `__construct(ServerRequestInterface $httpRequest, HttpContext $context)`
  works whatever the names are; explicit route bindings still win. The old
  `request`/`ctx` name convention is gone.
- Controllers returning `null` now throw (`Ex`): a forgotten `return` is no
  longer a silent 200 with an empty body. Return an explicit empty response
  (eg: 204 from the response factory) instead.
- `HttpContext::ensure()` is removed. The `Kernel` creates the context for
  every cycle; middlewares and controllers use `from()`/`tryFrom()`, the
  pipeline rebinds it when the request object changes.
- `Module` freezes after the `whenAllLoaded` second pass: mutating a module
  from `App::modules()` throws a `LogicException`.
- `Kaly\Http\HttpContext` moves to `Kaly\Core\HttpContext` (no alias).
- `Debug/functions.php` only provides `d()`/`dd()`: `env()` and `is_cli()`
  are removed (use `Kaly\Util\Env` and `php_sapi_name()`).

## Slimmer clocks

- `Kaly\Clock\AbstractClock` is removed: both clocks implement
  `Psr\Clock\ClockInterface` directly, which stays the only public contract.
- `new SystemClock()` now follows the PHP default timezone (so it sees
  `APP_TIMEZONE`) instead of imposing UTC. Be explicit when the business clock
  must stay UTC: `new SystemClock('UTC')`. `fromSystemTimezone()` and
  `fromUtc()` are removed; `freeze()` stays as a test convenience.
- `FrozenClock` now takes its instant as a required constructor argument and
  gains `modify('+2 hours')` for readable time travel, next to `setTo()`.
- Need monotonic time, a controllable `sleep()` or `ClockSensitiveTrait`?
  Any PSR-20 implementation (eg: `symfony/clock`) plugs in with no adapter:
  `$di->rebind(ClockInterface::class, $yourClock)`.

## Strict JSON boundary and mixed narrowing

- `Json::encode()` always produces real JSON: `encode('foo')` returns `'"foo"'`
  instead of the bare `foo`. It throws (`JSON_THROW_ON_ERROR`) on failure and
  substitutes invalid UTF-8.
- `Json::decode()` throws on malformed input only: the valid JSON document
  `null` now decodes to `null` instead of throwing. It takes a plain string
  (no `null` default, no `$assoc` flag).
- `decodeArr()`/`decodeObj()` are replaced by `decodeList()` (`list<mixed>`)
  and `decodeMap()` (`array<string, mixed>`): malformed JSON or the wrong
  outer shape throws a `JsonException`. The outer shape is read from the raw
  document, so the empty object `{}` is a valid `decodeMap()` (rejected by
  `decodeList()`), and `{"0": "x"}` decodes to int keys and is rejected by
  both.
- `Json::validate()` is minimal again (`validate(string $json): bool`): no
  flags, depth or nullable input.
- New `Kaly\Util\Types` for trivial `mixed` narrowing without conversion:
  `stringOrNull()`, `intOrNull()`, `boolOrNull()`, `listOrEmpty()`,
  `mapOrEmpty()`. `Types::intOrNull("42")` is `null`, never `(int) "42"`.

## kaly-di 0.3

- `composer.json` now requires `lekoala/kaly-di: ^0.3`.
- Replacing a service is explicit: `set()` on an already defined id, or merging
  two definitions that own the same id, throws a `DefinitionException`. Use
  `rebind()` for intentional replacements (a `configure()` hook overriding a
  module service, a test swapping an implementation), with the optional
  `expected` guard as a compare-and-swap. A later module can no longer silently
  override an earlier binding: rebind from `whenAllLoaded()` or a hook instead.
- New `alias()` makes an id resolve to another entry (same shared instance).
- `Container::get()` rejects parameters configured for a constructor that does
  not declare them (`DefinitionException`): a typo'd parameter name now fails
  fast instead of being ignored.
- `Injector::make()` / `invoke()` validate the argument list (unknown named
  arguments, duplicates, surplus positionals): only pass what the callable
  declares. The dispatcher forwards the current request and context to
  controllers by parameter type (`ServerRequestInterface`, `HttpContext`).
- A union parameter with several available candidates is ambiguous and throws
  `UnresolvableParameterException`: pass the dependency explicitly.

## Dump helpers

- `d()` dumps through Symfony VarDumper's `dump()` (`var_dump()` fallback) and
  never stops the execution: use the new `dd()` to dump and exit. The homemade
  rendering is gone (argument names from source parsing, HTML/CLI branches,
  output buffering), VarDumper owns that job, including server mode.
- `DUMP_EXCEPTION` is removed: `d()` no longer throws a `ResponseException`.
- The `APP_DEBUG` guard stays: `d()` does nothing in production.
- VarDumper file links use `xdebug.file_link_format`
  (eg: `vscode://file/%f:%l`), the Kaly debug page keeps using
  `DUMP_IDE_PLACEHOLDER` (eg: `vscode://file/{file}:{line}:0`).

## Pre-1.0 API cleanup: one routing exception, bare accessors, no RouteGroup

- **`Kaly\Router\RouteGroup` is removed.** `Routes::prefix()` and
  `Routes::middleware()` now return a `Routes` scoped view instead, and
  `Routes::group()` is the single way to declare inside it — the group writes
  into the routes you started with, however many scopes you derived. Declaring
  a route directly on a scope (`->prefix('/api')->get(...)`) throws instead of
  silently dropping it. `Routes::merge()` is private. Nothing referenced
  `RouteGroup` by type.
- **Url generation failures throw `Kaly\Router\RouteGenerationException`**
  instead of a bare `RuntimeException` (11 call sites in `Router` and
  `TableResolver`, plus one in `ConventionResolver`). It extends `Ex`, so one
  `catch (Ex)` now covers a broken config *and* a url that cannot be built,
  which is the split the old `RuntimeException` hid.
- **Accessors lose the `get` prefix where a fluent setter does not claim the
  name.** Rename:
  - `App::getModules()` → `modules()`, `getContainer()` → `container()`,
    `getKernel()` → `kernel()`, `getComposerInfo()` → `composerInfo()`
  - `Module::getDir()` → `dir()`, `getName()` → `name()`, `getId()` → `id()`,
    `getResolvers()` → `resolvers()`, `getClaims()` → `claims()`,
    `getConfigPath()` → `configPath()`, `getSrcDir()` → `srcDir()`,
    `getTemplatesDir()` → `templatesDir()`, `getAssetsDir()` → `assetsDir()`
  - `LocalizedTranslator::getLocale()` → `locale()`

  The `get` prefix stays where a setter owns the bare name — `App::locales()`
  sets and `getLocales()` reads, `Module::mount()`/`namespace()`/`priority()`/
  `whenAllLoaded()` set and their `get*` reads. A setter and its accessor
  cannot share a name, and the setters are what you write in `config.php`.
  `Translator` and `LocaleResolver` keep their `get*`/`set*` pairs throughout.
- `Arr::mergeDistinct()` takes its arguments by value. It mutated `$arr1` in
  place *and* returned the result; callers relied on the return value, and the
  in-place write no longer happens.
- `Str::slug()` trims leading and trailing separators on the ext-intl branch.
  The fallback already did, so the same call used to return `-mypage-` or
  `mypage` depending on whether ext-intl was installed.
- `Arr::mapAssoc()` preserves the keys. Its own docblock example
  (`fn($key, $value) => [$key => $value]`) could not work without that.

## Pre-1.0 consistency: one exception family, one emitter, one declaration order

- **Every client-facing failure is now a `Kaly\Http\Exception\HttpException`.** It extends
  `Kaly\Ex` and carries the status, the extra headers and the body.
  `NotFoundException`, `RouteNotFoundException`, `ForbiddenException`,
  `MethodNotAllowedException`, `InputException`, `ValidationException`,
  `RedirectException` and `ResponseException` all extend it, so one `catch`
  covers the whole family. `InputException` (400) and `ValidationException` (422)
  remain distinct failures, they are now siblings rather than unrelated classes.
- **`Kaly\Ex::getIntCode()` is removed, and `HttpExceptionInterface::getIntCode()`
  becomes `status()`.** The integer conversion belonged to HTTP, which is the
  only layer with a status: `Exception::getCode()` is typed `int|string`, and
  wrapping it in `intval()` gave every Kaly exception an artificial numeric
  contract. `Kaly\Ex` is now a bare marker, and `HttpException::status()`
  is the semantic accessor. A non-HTTP failure carries no status at all, which
  is what makes the `ExceptionHandler` guard meaningful.
- `ResponseEmitterInterface` is declared in `App::DEFAULT_IMPLEMENTATIONS` and
  resolved from the container in `App::run()`, so replacing the emitter is a
  `set()` and not a fork. Declaring the interface alone was a false
  extensibility: `App::run()` news the concrete class up inline.
- **Resolvers of equal priority now run in the order `config.php` reads as.**
  The route table keeps the position of its *first* `routes()` call, so a custom
  resolver declared after `routes()` runs after the table instead of before it.
  Priorities still win over the declaration order; `TableResolver::PRIORITY` (0)
  is now a named constant.
- A route handle that outlives the `group()` callback that created it configures
  its own route again. `get()` returns the `RouteDefinition` itself rather
  than an index into the table, so a group no longer absorbs a copy and drops
  the late mutation. `Routes::draft()` and `Routes::replaceDraft()` are
  removed; they were internal plumbing.

## Pre-1.0 correctness and API cleanup

Behaviour fixes and the small public API changes that go with them.

- **A camelized convention segment no longer redirects to itself.** `/shop/Cart/`
  resolved `CartController` but redirected to the identical url, in a loop. It
  now redirects to the canonical `/shop/cart/`, as `Str::decamelize()` spells it.
- `App::debug()` is the setter, `App::isDebug()` reads the state. A lot of code
  (and documentation) was calling `debug()` as a getter, which silently *enabled*
  debug mode.
- **Content negotiation is one implementation.** `Kaly\Http\Accept` parses the
  header once and `Kaly\Http\MediaType` owns the media type parsing, shared with
  `RequestUtils::getMediaType()`. The `q` weights are now honoured by
  `RequestUtils::getPreferredContentType()`, which previously returned the first
  `Accept` entry regardless of weight, and `ExceptionHandler::wantsJson()` no
  longer parses the header a second time. Wildcards are handled: an explicit
  `text/html;q=0.5` is not overridden by a later bare wildcard, and
  `application/problem+json` counts as JSON. The client leads, the server
  priority list breaks ties.
- `RequestUtils::getMediaTypeParams()` no longer reads past the end of a
  parameter that has no value (`Content-Type: text/html;charset` raised a warning
  and became a 500), and quoted values keep the separators they contain
  (`boundary="a;b"`).
- `CookiePolicy` canonicalises `sameSite` on the way in: `sameSite: 'STRICT'`
  validated but was dropped when the header was built. It now stores and emits
  `Strict`. Read it back from `$policy->sameSite` and compare against the
  canonical spelling.
- `RedirectException` only accepts a real redirect status (301, 302, 303, 307,
  308). `304` is not a redirect and no longer carries a `Location` header; use the
  literal `304` if you need to name it.
- `Kaly\Http\Exception\ForbiddenException` is added (403, empty body). Access control stays
  the application's, but the framework now has the class its own documentation
  used to reference.

## PSR-15 only middlewares

- `GeneratorMiddleware` and `GeneratorMiddlewareInterface` are removed. Write plain
  PSR-15 middlewares: `try/finally` around `$handler->handle()` for metrics and cleanup,
  `try/commit/catch/rollback` for transactions.

## Request-scoped sessions (superseded by the pre-1.0 cleanup above)

- `SessionFactoryInterface::create($request)` says where the session of a request comes
  from. `NativePhpSessionFactory` is the default; bind your own factory for concurrent
  runtimes. `HttpContext` takes it as a constructor dependency, the kernel passes it
  every cycle.
- `ArraySession` is concurrency-safe but persists nothing between requests: tests and
  isolated cycles only, never a production backend.

## Explicit controller results

- Controllers may return `Kaly\Http\JsonResponse::of($data, 201)` for JSON with a
  status code and headers (a plain array stays the shorthand for a 200 JSON response).
- `Kaly\View\View::of($template, $data)` builds a view; `withStatus(404)` answers with
  another status code. The dispatcher honors it when rendering.
- `AbstractController::redirectToRoute('shop:product', $params)` redirects to a named route
  (303 by default), generated for the request locale.
- `RequestDispatcher::prepareResponse()` now receives the `HttpContext` instead of the
  locale string.

## Slimmer response exceptions

- `ResponseException::svg()`, `::html()` and `::json()` are removed: shaped responses
  are controller results (`View`, `JsonResponse`), not exceptions. The class only
  carries a raw body (used by the debug dump).

## Immutable routes and contextual urls

- `Route` is now immutable and final: resolvers build it in one step with
  `$request->route($controller, $action, ...)` instead of `Route::to(...)` followed
  by property assignments. `Route::to()` is removed.
- `Route::$namespace`, `Route::$segments`, `Route::$definition` and `Route::toArray()`
  are removed. The qualified name is on `Route::$name`, the module namespace on
  `Route::$module`.
- `HttpContext::url()` and `HttpContext::urlFor()` generate for the request locale;
  `Router::url()` / `Router::urlFor()` keep their explicit locale for uses outside
  a request. Templates receive a `$url` generator (like `$i18n`): both names are
  reserved in view data.

## Route middlewares and request state

- Middlewares declared on routes and groups (`->middleware()` in a route table) are now
  **executed** by the framework, right before the controller. They were previously only
  stored. Check that every declared middleware is a real PSR-15 or generator middleware:
  an unknown class now fails at boot.
- New `#[Kaly\Router\Middleware(...)]` attribute on controllers and actions.
- Session and cookie changes made through `$ctx->session()` / `$ctx->cookies()` are now
  written to the response by the kernel. Remove any manual `addToResponse()` call, or
  cookies will be emitted twice.
- `NativePhpSession` never lets PHP send its own session cookie and cache
  headers: the provider writes the cookie on the PSR-7 response.
- `CookiePolicy` rejects a SameSite value other than `None`, `Lax` or `Strict`.

## One App, typed hooks, closure configs

### Entry point

```php
// before
$psr17Factory = new Nyholm\Psr7\Factory\Psr17Factory();
$creator = new Nyholm\Psr7Server\ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
ErrorHandler::handle(function () use ($creator) {
    $app = new App(dirname(__DIR__));
    $app->boot();
    $app->run($creator->fromGlobals());
});

// after
App::create(dirname(__DIR__))->run();
```

- `Kaly\Core\Application` is merged into the final `Kaly\Core\App`. Extending the app is
  no longer possible: use hooks and definitions.
- PSR-17 factories are discovered (Nyholm, Guzzle, Laminas, HttpSoft): remove the six
  `bind(...Factory::class, Psr17Factory::class)` lines. An explicit binding still wins.
- `run()` builds the request from the globals when called without one, and turns a boot
  failure into a 500. `handle()` and `run()` boot the app when needed.

### Hooks

| Before | After |
| --- | --- |
| `addCallback(App::CB_BEFORE_DEFINITIONS, $fn)` / `CB_AFTER_DEFINITIONS` | `configure($fn)` (runs after the modules, wins over the framework defaults) |
| `addCallback(App::CB_BOOTED, $fn)` | `onBoot($fn)` |
| `addCallback(App::CB_ERROR, $fn)` | `onError($fn)` |
| `addCallback(App::CB_AFTER_REQUEST, $fn)` | `onTerminate($fn)` |
| `addCallback(App::CB_BEFORE_REQUEST, $fn)` | an `incoming` middleware |
| `addCallback(App::CB_FINALIZE_RESPONSE, $fn)` | `middleware()->outgoing($fn, always: true)` |

`configure()`, `onBoot()` and `debug()` must be called before boot.

### Renamed accessors

| Before | After |
| --- | --- |
| `setDebug()` / `getDebug()` | `debug()` / `isDebug()` |
| `getBooted()` | `isBooted()` |
| `getBaseDir()`, `getPublicDir()`, `getTempDir()`, `getTempDirFor()`, `getModulesDir()`, `getResourcesDir()` | `paths()->base`, `paths()->publicDir()`, `paths()->temp()`, `paths()->tempFor()`, `paths()->modules()`, `paths()->resources()` |
| `App::FOLDER_*` constants | `Paths::MODULES`, `Paths::PUBLIC`, `Paths::TEMP`, `Paths::RESOURCES` |
| `getCache()`, `cachedData()`, `App::APP_CACHE` | removed: inject a `CacheInterface` |
| `getRequestHandler()` | removed: `getKernel()` |

### Module config.php

```php
// before
/** @var Kaly\Core\Module $this */
$this->setPriority(50);
$this->definitions()
    ->bind(Foo::class, Bar::class)
    ->lock();

// after
return static function (Module $module, Definitions $di): void {
    $module->priority(50);
    $di->bind(Foo::class, Bar::class);
};
```

- No `$this`, no manual `lock()`. A config using `$this` fails with an explicit message.
- `setPriority()` → `priority()`, `setNamespace()` → `namespace()`,
  `setDefinitionsCallback()` → `whenAllLoaded()`.

## Hierarchical routing

Every url now belongs to exactly one module, which resolves it alone, and everything a
module answers is declared in its `config.php`. There is no global route table anymore.

```text
/fr/boutique/panier/   locale -> entry point (claim > mount > default module) -> module resolvers
```

### routes.php and #[RouteAttribute] are gone

Declare the routes in `config.php`. **Paths are now relative to the module mount**:

```php
// before: modules/Shop/routes.php
return static function (Routes $routes): void {
    $routes->get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show');
    $routes->get('/about', [AboutController::class, 'index']);
};

// after: modules/Shop/config.php
return static function (Module $module, Definitions $di): void {
    $module->routes(function (Routes $routes): void {
        $routes->get('/{slug}', [ShopController::class, 'show'])->name('show');   // /shop/{slug}
    });
    // a path outside of the module segment is claimed explicitly
    $module->claim('/about', function (Routes $routes): void {
        $routes->get('/', [AboutController::class, 'index'])->name('about');
    });
};
```

- A `routes.php` file is ignored: move it into `config.php`.
- `#[RouteAttribute]` is removed: declare the route in the module table. Scanning every
  controller of every module at boot was the only boot cost growing with the size of
  the application.
- Paths that belong to the site root go in the module mounted on `/`.

### Route names are qualified by module

```php
// before
$router->generate('shop.show', ['slug' => 'books']);
$router->generate([CartController::class, 'add']);

// after
$router->url('shop:show', ['slug' => 'books']);   // the default module may omit its prefix
$router->urlFor([CartController::class, 'add']);
```

- `RouterInterface::generate()` is replaced by `url()` (named routes) and `urlFor()`
  (conventional urls). The locale is an explicit argument, not a `locale` param.
- The `RouterInterface::MODULE`, `CONTROLLER`, `ACTION`... constants are removed.

### Locales belong to the app, the prefix to the module

```php
// before, in any module config
->callback(ClassRouter::class, fn(ClassRouter $r) => $r->setAllowedLocales(['en', 'fr'], ['LangModule']))

// after
APP_LOCALES=en,fr                     // or $app->locales(['en', 'fr'])
$module->localized();                 // in the config.php of LangModule itself
```

- Modules are **not localized by default**: a locale prefix on them redirects to the url
  without it. Call `localized()` on every module whose urls carry the locale.
- A module can have one segment per locale: `$module->mount(['fr' => 'boutique', 'en' => 'shop'])`,
  and a route one path per locale: `$routes->get(['fr' => '/a-propos', 'en' => '/about'], ...)`.
  Both require `localized()` on the module, and `localized()` requires the application
  to declare locales: anything else fails at boot (mounts, claim prefixes) or when the
  table compiles (paths).
- Generating an url for a locale the route or the mount has no variant for fails instead
  of producing an url no route would match.
- Every `$module->routes()` call feeds the same table: use the priority of each route
  (`->priority(10)`) instead of the removed second argument of `routes()`. A name shared
  by two declarations of the table fails when it compiles; a name shared with a claim
  fails when the url is generated.
- A 405 from the route table is authoritative: custom resolvers and the convention never
  reinterpret a path the table knows for other methods only.
- The redirect adding the default locale now builds an absolute path (`/en/...`).

### Removed classes

`ClassRouter`, `CompositeRouter`, `RouteCollectionRouter`, `AttributeRouteLoader`,
`RouteAttribute` and `AmbiguousRouteException` are removed. Their roles are held by
`Router` (entry points, locales, generation), `ConventionResolver`, `TableResolver`
and `ResolverInterface` for custom resolvers (`$module->resolver(...)`).

### Convention routing

- Every module is routable by convention under its decamelized name, without any
  configuration. **Review modules that were not exposed before**: opt out with
  `$module->withoutConventionRouting()`, or choose the segment with `$module->mount()`.
- A url that the module of its segment cannot resolve is a 404: it never falls back to
  another module.
- `Route::$module` is the module namespace (`TestVendor\MappedModule`).
- `Route::$name` holds the qualified name (`shop:show`), `Route::$bindings` the objects
  a resolver hands to the controller constructor.

## Error responses

- `ExceptionHandlerInterface::toResponse(Throwable $exception, ?ServerRequestInterface $request = null)`
  receives the request: update custom handlers.
- `RouteNotFoundException` is now a `NotFoundException` (an HTTP exception): a 404 is
  no longer logged nor reported to `onError()`, and its body is `Not Found` instead of
  `Server error`.
- A resolver may throw `RouteNotFoundException` to say why it does not know an url;
  `ConventionResolver::resolve()` does so and never returns `null`.
- JSON clients get `application/problem+json` error responses. Plain responses now
  carry a `Content-Type` (`text/plain; charset=utf-8`, or HTML for the debug page).
- `ExceptionHandler` takes a `debug` flag, set from the app debug mode. It no longer
  uses `ErrorHandler::generateError()`, which only renders errors outside of a request
  cycle (a failing boot).
- Failing `config.php` files and invalid route tables are wrapped in an `Ex` naming the
  module, with the original exception as previous.

---
layout: default
title: Versioning
nav_order: 28
---
# Versioning

Kaly follows [SemVer](https://semver.org). Before 1.0, a minor release (`0.7`,
`0.8`) may break; after 1.0, only a major does. This page states what the
promise covers, so that "breaking" has a precise meaning before and after 1.0.

A 1.0 promises what it will no longer break, not what it adds: the 1.0 tag
contains no new feature, only the last 0.x minus its pre-1.0 warnings.

## Tiers

Every public symbol belongs to one of three tiers:

| Tier | Meaning | How to recognize it |
|---|---|---|
| **API** | Stability promised | Everything public that is not in the two tiers below |
| **Accessible internal** | Public only because the plumbing needs it; may change in any release | `@internal` on the class or method |
| **Experimental** | Shipped, named explicitly, not yet promised | Listed in [Experimental](#experimental) below, never implied |

Experimental is never a catch-all: a symbol is experimental only when this page
names it. Using an `@internal` symbol from an application is a friction to
report: either Kaly exposes a supported API for the need, or the usage goes away.
`@internal` does not forbid Kaly's own test suite: it may inspect internals
(`RouteCollection::toArray()`) to verify a contract.

### Experimental

- `Kaly\Core\Preload`: the recording and the generated script may change shape.

## Deriving the public surface

The public surface is derived, not maintained by hand. In order of priority:

1. what `docs/` explicitly presents to applications;
2. what an application must *implement* (interfaces, extension points);
3. what the witness application uses **among the promised APIs**.

The witness application confirms, it does not promote: if it touches an
`@internal` or experimental symbol, that is a friction to resolve, not a reason
to promise the symbol. The 1.0 surface is extracted from these three sources,
then reviewed. See the [inventory](#inventory-v0) for the current state.

## Interfaces implemented by applications

Adding a required method to a public interface breaks every implementation.
When a capability evolves, it goes through a separate, optional interface
(`ScheduledTaskDispatcher` next to `TaskDispatcher` is the model), or waits for
a major. This is the easiest break to cause by accident: review every change to
an interface listed in the inventory with this rule in mind.

## Supported versions

| Dependency | Promised |
|---|---|
| PHP | `^8.3` |
| PSR-7 messages | `psr/http-message` 2 |
| PSR-3 logger | `psr/log` 1, 2 and 3 |
| Twig, through `TwigRenderer` | 3 |
| Latte, through `LatteRenderer` | 3 |
| Symfony Translation, through `SymfonyTranslator` | 7 |

Adding support for a new major of a dependency is a **minor** change, as long as
the majors already promised stay supported. Raising the PHP minimum or dropping
a promised major is a **major** change.

## Behavioral stability

The promise covers documented behavior, not only signatures:

- conventional url shapes (dashed actions, invokable controllers, mounts, locale
  prefixes);
- `404` and `405` responses, and that a `405` is authoritative: a path known
  for other methods is never reinterpreted by a later resolver;
- canonical redirects: `307` by default, so the HTTP method is preserved;
- route precedence: declared table, convention, then resolvers in declaration
  order, claims as separate islands;
- the error page, locale resolution and session cookies.

A helper declared internal may still shape public behavior: `Str::camelize()`
builds conventional urls, so the urls it produces are frozen even if the
algorithm is not promised as a function.

## Breaking changes

A breaking change needs, in the same change:

- a demonstrated reason — an architectural or semantic problem, not naming,
  symmetry or internal organization;
- an entry in `UPGRADE.md`;
- the migration of the witness application.

## Deprecation

From 1.x, a deprecated API (`@deprecated` plus a migration note) stays
supported until a major allows its removal. Before 1.x there is no notification
mechanism: breaks go through `UPGRADE.md`.

## Lazy compilation

A route table compiles at its first use, and the compilation validates every
declaration of the table: middleware, action signatures, duplicate names,
colliding routes, missing actions. A request reaching a table therefore
validates the whole table. A table that is never reached — an unvisited claim, a
table preceded by a resolver that answers first, a module no test visits — stays
unvalidated. This is a choice, not a guarantee: test each table at least once.

An explicit verification entry point (such as a `compileAll()`) will only be
added on demonstrated application need, never on `RouterInterface`. If it ever
exists, it must be reachable: the container exposes `RouterInterface` only, so
`Router::class` would also be registered on the same instance.

## Detection

When an application declaration is wrong, Kaly reports it at the earliest step
that already has the information — boot, table compilation, or first
resolution — and the message names what is needed to fix it (service,
parameter, route, provenance, cause). `tests/DiagnosticsTest.php` locks the level
of detection and the information carried by each message, not its wording.
Kaly is not a static analyzer: a mistake that is only visible when the code runs
is detected when it runs.

| Mistake | Detected at |
|---|---|
| Missing middleware on a route | Table compilation |
| Action signature the url cannot satisfy | Table compilation |
| Duplicate route name | Table compilation |
| Routes colliding on equivalent canonical patterns | Table compilation |
| Route targeting a missing controller or action | Table compilation |
| `localized()` without locale prefixes, or with undeclared locales | Boot (module registration) |
| Per-locale paths in a non localized module, prefixes enabled | Table compilation |
| Unresolvable constructor dependency | First resolution |
| Binding to a missing class | Boot with `zend.assertions=1` (development), first resolution otherwise |
| Service defined by two modules (`merge()` conflict) | Boot, naming both modules |

Known limits, kept on purpose:

- route collisions compare canonical patterns, they do not prove regex
  equivalence: `\d+` and `[0-9]+` are not reported as colliding;
- per-locale paths without prefixes in a non localized module are not an error:
  distinct full paths (`/medecins/`, `/artsen/`) match and generate symmetrically.

## Naming

Names are not changed for uniformity. `TaskDispatcher`, `Translatable`,
`TrustedProxy` and `RouteLocale` keep their names; suffixes (`Interface`,
`Middleware`, `Exception`) are kept where they distinguish a role. This decision
is recorded so that it is not reopened.

## Inventory v0

A first pass, extracted from `docs/` and `src/` before the witness application
exists. It lists entry points and the interfaces applications implement, to spot
early any `@internal` symbol used as an extension point. Fine classification
waits for the witness application.

### Entry points

| Area | Symbols |
|---|---|
| Composition | `Core\App`, `Core\Module`, `Core\Paths`, `Di\Definitions` (kaly-di), `Util\Env` |
| Routing | `Router\Routes`, `Router\RouterInterface`, `Router\TrailingSlash`, `Router\RestActionNaming`, `Http\Exception\RedirectException` |
| Request cycle | `Core\HttpContext`, `Core\AbstractController`, `Core\ViewResponder`, `Http\JsonResult`, `Http\FileResponseFactory`, `Http\ConditionalRequest`, `Http\RequestUtils` |
| Input and validation | `Http\Input\RequestInput`, `Http\Input\InputMapperInterface`, `Validation\Validator`, `Validation\ValidationResult`, `Validation\Violation`, `Core\ViolationMessageResolver` |
| Errors | `Ex`, `Http\Exception\HttpException` and its subclasses, `Http\ExceptionHandlerInterface`, `Core\LocalizedExceptionHandler` |
| Middleware | `Http\Middleware\*` (`TrustedProxy`, `FileServer`, `PreventSensitivePathAccess`, …), `Core\Middleware\RouteLocale`, `Core\Middleware\RequestIdHeader`, `Asset\Middleware\AssetServer`, `Auth\Middleware\BasicAccessMiddleware` |
| Security | `Http\Csrf\Csrf`, `Http\Csp\Csp`, `Crypto\Hmac`, `Crypto\Secret`, `Auth\PasswordHasher`, `Auth\SessionAuthentication`, `Auth\PermissionSet` |
| Views and i18n | `View\View`, `View\Adapter\{Twig,Latte,KalyTpl}Renderer`, `I18n\Translator`, `I18n\Locale` |
| Tasks | `Task\DirectTaskDispatcher`, `Test\RecordingTaskDispatcher` |
| Foundations | `Clock\SystemClock`, `Clock\FrozenClock`, `Log\FileLogger`, `Util\{Args,Arr,Assert,Cast,Dates,Fs,Json,Str,Types}` |
| Observability | `Debug\OpcacheStatus`, `Debug\Profile`, `Debug\ServerTiming` |
| Tests | `Test\TestClient`, `Test\TestResponse`, `Test\MemorySessionProvider` |
| Experimental | `Core\Preload` |

All symbols are under `Kaly\`.

### Interfaces applications implement

| Interface | Extension point |
|---|---|
| `Router\ResolverInterface` | Dynamic routing (CMS pages, legacy urls) |
| `Http\Input\RequestInput`, `Http\Input\ValidatableInput` | Typed request input |
| `Validation\HasValidationResult` | Exceptions carrying a validation outcome |
| `Http\ExceptionHandlerInterface`, `Http\ErrorPageInterface`, `Http\DebugPageInterface`, `Core\ErrorViewInterface` | Error handling and rendering |
| `Http\Session\SessionProviderInterface`, `Http\Session\SessionInterface` | Session storage for concurrent runtimes |
| `Core\Middleware\OutgoingInterface` | Outgoing middleware |
| `View\RendererInterface`, `View\RenderEnvironmentInterface` | Template engines |
| `I18n\TranslatorInterface`, `I18n\Translatable` | Translation backends, translatable values |
| `Asset\AssetsInterface` | Asset url strategy |
| `Task\TaskDispatcher`, `Task\ScheduledTaskDispatcher` | Background tasks |

PSR-15 `MiddlewareInterface` and PSR-11 containers are standards, not Kaly
contracts.

### Findings

- No `@internal` symbol is documented as an extension point. `Hooks` and
  `RoutingHandler` are named in the guides to explain the cycle, and are reached
  through `App::onBoot()`/`onError()`/`onTerminate()` and the fixed routing step.
- `RouteCollection::toArray()` is `@internal`: the test suite uses it to verify
  route contracts; applications list routes through `urlFor()` instead.

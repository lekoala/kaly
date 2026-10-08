---
layout: default
title: Building a Kaly application
nav_order: 4
---
# Building a Kaly application

[Modules](modules.md) explain what an application is made of. This page explains
how to organize what goes inside one, where an integration belongs, and how a domain
error becomes an HTTP response.

The structure below is **recommended, never imposed**. Kaly knows only `config.php`,
`src/`, `templates/` and `assets/`; the rest is a convention. It is worth following
when a module grows, because it keeps behaviour, orchestration, adapters and
transport apart — the boundaries a human and an agent both navigate by.

## Module layout

```text
modules/
  Booking/
    config.php
    src/
      Controller/
      Application/
      Domain/
      Infrastructure/
    templates/
```

Not every module needs all four folders. Start with the one or two it actually has
and let the others appear when a real boundary justifies them: a five-class module
does not need twelve folders. The framework only sees `config.php`, `src/`,
`templates/` and `assets/` (see [Modules](modules.md#layout)); the subfolders are a
convention inside `src/`.

The namespace defaults to the camelized folder name, so a class in
`modules/Booking/src/Domain/Booking.php` is `Booking\Domain\Booking`. Declare the
module in `composer.json` as usual:

```json
{
  "autoload": {
    "psr-4": {
      "Booking\\": "modules/Booking/src/"
    }
  }
}
```

## The four layers and their dependencies

| Layer            | May depend on                                                                 | Never                                              |
| ---------------- | ----------------------------------------------------------------------------- | -------------------------------------------------- |
| `Domain`         | `Kaly\Util`, `Kaly\Clock`, plain PHP, domain value objects                    | `Kaly\Http`, `Kaly\Core`, `Kaly\View`, `ServerRequestInterface` |
| `Application`    | `Domain`, its own ports, `Kaly\Util`, `Kaly\Clock`                            | `Kaly\Http`, `Kaly\View`, infrastructure           |
| `Infrastructure` | `Domain` and `Application` ports, any external library (PDO, DBAL, SDK…)      | — (it is the adapter layer)                        |
| `Controller`     | `Application`, `Domain`, `Kaly\Http`, `Kaly\Core`, `Kaly\View`                | infrastructure details                             |
| `config.php`     | everything: it is the module's composition root                               | —                                                  |

> Behaviour lives in `Domain`; orchestration in `Application`; adapters in
> `Infrastructure`; transport in `Controller`; wiring in `config.php`.

`Domain` may use the Kaly foundations `Kaly\Util` and `Kaly\Clock` because they carry
no application model: a slug helper and a clock are as neutral as `\DateTimeImmutable`.
It deliberately does **not** get `Kaly\Ex`: a domain exception can extend
`\RuntimeException` or `\DomainException`, and `Kaly\Ex` adds no architectural
contract. The domain stays independent of Kaly.

`Infrastructure` is where the outside world is allowed in. A `PdoBookingRepository`
implements a `BookingRepository` port declared by the domain or application, and the
port is what everything else depends on. See [Persistence](database.md), which is this
same rule applied to databases.

## HTTP action styles

Kaly does not prescribe how HTTP actions are organized. A route targets either a
public controller method or an invokable class, and the dispatcher treats both the
same:

```text
Route
  -> HTTP action (public controller method, or invokable class __invoke)
  -> Application use case / Domain
  -> View / Response / Redirect
  -> responder (dispatcher + ViewResponder)
```

Two shapes are first-rank, and mixing them is normal:

**Multi-action controller.** Several related actions in one class: few files, handy
for CRUD and small surfaces. The conventional url `controller/action` derives from
the class and method names; an explicit route to the same method works too and takes
ownership of the action.

```php
final class BookingController
{
    public function show(string $id): View {}

    public function confirm(string $id): View {}
}
```

**Single-action / invokable class.** One class per endpoint, routed as
`Action::class` (its `__invoke` method). Maximum isolation, fitting complex
orchestration, API endpoints and thin handlers. It needs neither a base class nor an
interface:

```php
final class ConfirmBookingAction
{
    public function __invoke(string $id): View {}
}
```

```php
$routes->get('/booking/{id}', [BookingController::class, 'show']);
$routes->post('/booking/{id}/confirm', ConfirmBookingAction::class);
```

Both map onto ADR with no Kaly interface: the **Action** is the controller method or
the `__invoke`, the **Domain** is the `Application` layer, the **Responder** is the
dispatcher plus `ViewResponder`. An action returns a `View`, a `JsonResult`, an array
or a string; to redirect it throws `Kaly\Http\Exception\RedirectException` — via
`AbstractController::redirectToRoute()` when it extends the base class, or directly
from an invokable action. Prefer [explicit routes](routing.md#the-route-table) so each
action's url and middleware policy stay visible.

## Verify the boundaries with Mago Guard

Conventions erode. Mago's **perimeter guard** turns these directions into a check:
if someone puts `ServerRequestInterface` in `Domain\Booking`, the build fails instead
of the boundary quietly disappearing.

A rule's source `namespace` is a concrete namespace (it ends with `\`), not a glob, so
the guard names the modules it protects explicitly. Repeat the template per module
root:

```toml
[guard]
mode = "perimeter"

[guard.perimeter.layers]
kaly-foundations = [
    "@native",
    "Kaly\\Util\\**",
    "Kaly\\Clock\\**",
]

[[guard.perimeter.rules]]
namespace = "Booking\\Domain\\"
permit = [
    "@self",
    "@layer:kaly-foundations",
]

[[guard.perimeter.rules]]
namespace = "Booking\\Application\\"
permit = [
    "@self",
    "@layer:kaly-foundations",
    "Booking\\Domain\\**",
]

[[guard.perimeter.rules]]
namespace = "Booking\\Controller\\"
permit = [
    "@self",
    "@layer:kaly-foundations",
    "Booking\\Domain\\**",
    "Booking\\Application\\**",
    "Kaly\\Http\\**",
    "Kaly\\Core\\**",
    "Kaly\\View\\**",
]

[[guard.perimeter.rules]]
namespace = "Booking\\Infrastructure\\"
permit = ["@all"]
```

A namespace with no matching rule is denied, not allowed — every layer must be listed.
`Infrastructure` is the exception: it gets `@all` because it is the layer that reaches
outward, including vendor packages. `config.php` is not a namespace, so the guard does
not cover it — it is where every layer is composed on purpose.

Make sure `[source].paths` in `mago.toml` covers your `modules/` directory, otherwise
the guard never sees the code it is meant to protect.

> Repeat these rules for each module root, or adapt them to the namespace layout of
> the application.

## What the guard does not catch

Mago has two halves, and it is worth knowing which one this recipe uses:

```text
Perimeter guard   -> dependency direction          <- configured above
Structural guard  -> naming, modifiers, inheritance, attributes
```

The perimeter guard answers *may this type depend on that type*. It does not answer
the invariants that are not about a single `use` statement:

```text
Not mechanically guaranteed by this recipe
  -> the container is only read in composition/bootstrap
  -> RequestInput only appears at the HTTP boundary
  -> request-scoped state is never stored in a shared service
```

Mago's structural rules can check some naming and modifier conventions too; this
profile simply does not pretend to check everything. The three points above stay
conventions that review and [Runtime](runtime.md) discipline must hold.

## Where does an integration go?

The rule that decides everything else:

> Kaly adds an abstraction only when Kaly itself must call the capability.

Three seams follow. Ask which one the capability falls into before writing anything:

| Seam | When | Shape | Examples |
| --- | --- | --- | --- |
| **1. Inject the library** | Kaly never has to call it; only application code does | the library is a constructor dependency of an application service | OTPHP, Symfony Mailer, Doctrine, an S3 SDK |
| **2. Kaly port + adapter** | Kaly itself must call the capability during a cycle, or needs a stable app-facing contract | a small interface owned by Kaly, an adapter around the real library | `RendererInterface`, `TranslatorInterface`, `SessionProviderInterface`, `InputMapperInterface` |
| **3. Middleware (or a hook)** | the capability acts on the HTTP cycle: it reads the request, transforms the response, or observes the cycle | a PSR-15 middleware, optionally reading [`HttpContext`](http-context.md); observation also fits `onError()`/`onTerminate()` | CSRF, auth, rate limiting, Sentry |

Most real integrations combine seams. Stripe is seam 1 (inject the SDK) plus seam 3
(a webhook route with a signature-verifying middleware). OAuth is seam 1 (the provider
client) plus seam 3 (the callback route's middleware). That is normal: each part
lands where it belongs.

| "Where does it go?" | Seam |
| --- | --- |
| Redis cache / queue client | 1 — inject it into the use case that needs it |
| S3 / filesystem storage | 1 — inject the client, hide it behind an application port |
| Sentry / OpenTelemetry | 3 — `onError()`/`onTerminate()` and an outgoing middleware |
| OAuth / JWT client | 1 for the client, 3 for the callback route |
| Unpoly / Turbo (headers) | 3 — an outgoing middleware |
| Stripe / payment SDK | 1 for the SDK, 3 for the webhook route |
| Feature flags | 1 — inject the client into the services that read flags |

### The three seams, in Kaly's own code

- Seam 2 exists in Kaly only where the framework needs it: the dispatcher renders a
  `View` ([Views](views.md)), the exception handler translates ([i18n](i18n.md)),
  the context starts a session ([Runtime](runtime.md)), the dispatcher maps an input
  ([Request input](input.md)). Each is an adapter around a real engine.
- Seam 1 is what [Mailer](mailer.md) documents: Symfony Mailer is injected directly
  because Kaly has no email concept to own.
- Seam 3 is the whole point of [Security](security.md), [Auth](auth.md) and
  [Logging](logging.md): the capability plugs into the cycle without Kaly knowing its
  internals.

If a capability does not fit any seam, that is the signal that it is trying to become
a sub-system. Keep it external; expose a port or a middleware.

## Domain errors → HTTP

A domain error carries meaning, not a status. The status is born at the HTTP
boundary, where someone knows the transport:

```text
Booking\Domain\BookingAlreadyCancelled   (extends \RuntimeException or \DomainException)
                ↓
      Application / HTTP adapter
                ↓
         409 Conflict
```

The domain never imports `Kaly\Http`. The mapping is a decorator of the existing
`Kaly\Http\ExceptionHandlerInterface`, so no new interface and no dependency leak is
needed.

An app-local HTTP exception carries the status:

```php
namespace App\Http\Exception;

use Kaly\Http\Exception\HttpException;
use Throwable;

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?Throwable $previous = null)
    {
        parent::__construct($message, 409, [], $previous);
    }
}
```

The decorator handles one mapping and delegates everything else:

```php
namespace App\Http;

use App\Http\Exception\ConflictException;
use Booking\Domain\BookingAlreadyCancelled;
use Kaly\Http\ExceptionHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class DomainExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly ExceptionHandlerInterface $next)
    {
    }

    public function toResponse(Throwable $exception, ?ServerRequestInterface $request = null): ResponseInterface
    {
        if ($exception instanceof BookingAlreadyCancelled) {
            $exception = new ConflictException($exception->getMessage(), previous: $exception);
        }

        return $this->next->toResponse($exception, $request);
    }
}
```

Bind it in the composition root. `configure()` runs after the framework defaults, so
`rebind()` replaces Kaly's handler. Wire the inner handler explicitly: binding the
decorator with an autowired `ExceptionHandlerInterface` would resolve to the decorator
itself.

```php
use App\Http\DomainExceptionHandler;
use Kaly\Core\LocalizedExceptionHandler;
use Kaly\Di\Definitions;
use Kaly\Http\ExceptionHandlerInterface;
use Psr\Container\ContainerInterface;

$app->configure(function (Definitions $di): void {
    $di->set(DomainExceptionHandler::class, static fn (ContainerInterface $c): DomainExceptionHandler =>
        new DomainExceptionHandler($c->get(LocalizedExceptionHandler::class)));
    $di->rebind(ExceptionHandlerInterface::class, DomainExceptionHandler::class);
});
```

Wrap `Kaly\Core\LocalizedExceptionHandler` to keep translated public bodies, or
`Kaly\Http\ExceptionHandler` to drop them (see [Logging](logging.md#errors)). A domain error left unmapped is not an
`HttpException`, so it becomes a `500` and is logged and reported to `onError()`: the
default is right for a real bug, and mapping is only needed for the domain errors that
are expected outcomes.

## Custom HTML error pages

An application 404 belongs to error handling. Let routing fail normally instead
of adding a catch-all route, `FallbackResolver` or dedicated `404Controller`.

Bind `Kaly\Core\ErrorViewInterface` to select production error templates:

```php
namespace App\Http;

use Kaly\Core\ErrorViewInterface;
use Kaly\Core\HttpContext;
use Kaly\View\View;
use Throwable;

final class SiteErrorView implements ErrorViewInterface
{
    public function view(Throwable $exception, HttpContext $ctx, int $status): ?View
    {
        return match ($status) {
            404 => View::of('@app/errors/404', ['status' => $status]),
            500 => View::of('@app/errors/500', ['status' => $status]),
            default => null,
        };
    }
}
```

Register it in module configuration or application configuration:

```php
use App\Http\SiteErrorView;
use Kaly\Core\ErrorViewInterface;
use Kaly\Di\Definitions;

$app->configure(static function (Definitions $di): void {
    $di->bind(ErrorViewInterface::class, SiteErrorView::class);
});
```

`null` keeps Kaly's standard error response. The view's status cannot change the
error status; Kaly preserves the exception's status and headers. There is no
implicit error-template naming convention: the application chooses its module,
templates and renderer.

Error views pass through `Kaly\Core\ViewResponder`, just like controller views.
They receive `i18n`, `url`, `asset`, `auth`, `csrf` and `csp`, bound to the current
request. With kaly-tpl 0.2 these helpers also reach layouts and partials. Reading
authentication or the CSRF field name does not create a session; requesting a CSRF
token can create one, as in an ordinary view.

A routing failure may occur before a locale is established. In that case, Kaly
uses `LocaleResolver` to negotiate from the request locale attribute,
`Accept-Language`, then the application default. An unmatched `/en/...` URL
prefix alone does not establish the locale. An already established locale is
preserved.

The error view is a production fallback. These responses take precedence:

1. An HTTP exception with an explicit `Content-Type`.
2. A JSON client's `application/problem+json` document.
3. An HTTP exception's non-empty public body.
4. The debug page or debug text.

If selecting or rendering an error view throws, Kaly logs that failure and
returns the original error's standard response, without trying another error
view. Templates should show a public message rather than the raw exception.

For an HTTP-only integration without Kaly runtime state, bind
`Kaly\Http\ErrorPageInterface` to supply an HTML string or `null` instead.
The Core bridge is installed automatically when an error view is bound and no
explicit error-page implementation exists. Keep broader error conversion, such
as domain-to-HTTP mapping, at `ExceptionHandlerInterface`.

### The error responder seam

Rendering an HTTP error with the application's normal renderer needs no new
abstraction. The boundary `Throwable -> ResponseInterface` is
`Kaly\Http\ExceptionHandlerInterface`, and the per-status template selection is
`Kaly\Core\ErrorViewInterface` (`Kaly\Http\ErrorPageInterface` for an HTTP-only
integration without Kaly runtime state). Mapping a status to a template is an
ordinary `match`:

```php
return match ($status) {
    403 => View::of('@app/errors/403', ['status' => $status]),
    404 => View::of('@app/errors/404', ['status' => $status]),
    429 => View::of('@app/errors/429', ['status' => $status]),
    500 => View::of('@app/errors/500', ['status' => $status]),
    default => null,
};
```

A proposed `ErrorResponderInterface` that returns a response directly would
duplicate `ExceptionHandlerInterface`; the fallback when nothing is selected
already exists, since `null` keeps Kaly's standard response.

The selector is only consulted when the exception exposes no public body. A
non-empty `getResponseBody()` is an explicit representation and takes precedence
over the error view, so a message-bearing `HttpException` answers with plain
text instead. An exception that wants the application template leaves its body
empty; this is how `NotFoundException` (404), `ForbiddenException` (403) and
`TooManyRequestsException` (429, with an optional `Retry-After`) opt into
`ErrorViewInterface` while keeping their status and headers.

An error view is not a re-render of a form. It receives the request capabilities
Kaly reserves (`i18n`, `url`, `asset`, `auth`, `csrf`, `csp`) but none of the
submitted values. Preserving values across an error — and never re-displaying a
submitted password, for example after a `429` — is an explicit application
decision, not something the handler restores for you.

## Summary

```text
structure     behavior | orchestration | adapters | transport | wiring
integration   inject   | Kaly port     | middleware/hook
errors        domain meaning -> HTTP status at the boundary
```

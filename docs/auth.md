---
layout: default
title: Auth
nav_order: 12
---
# Auth

Authentication is identification (who is this?), authorization is permission
(may they do this?). Kaly keeps both out of the action: the request carries
its identity, guards run in the routed band, and the use case still owns its
object-level rules.

The boundary is deliberate:

> **Kaly transports and establishes an authenticated identity. It never decides
> what a user is or how business credentials are validated.**

That means Kaly provides the current identity, the session lifecycle, the
`Authorization` parsing, a permission set, CSRF and method override — while
the application owns the user repository, password checking, roles and domain
policies. Password hashing (`password_hash()` / `password_verify()`),
remember-me storage, JWT, OAuth and OIDC stay outside: external mechanisms
all converge on the same `Authentication`.

## Current identity

`HttpContext::auth()` is the single access point. It is request-scoped and
worker-safe, like the route, the locale and the session:

```php
$auth = $ctx->auth();

$auth->isAuthenticated();
$auth->principal();            // object, throws when anonymous
$auth->principalAs(User::class);
$auth->allows(Permission::AdminAccess);
```

The principal is any application object — `User`, `Admin`, `ApiClient` — never
a framework `UserInterface`. A public page reads it optionally, a guard
requires it explicitly. There is deliberately no `User $user` injection in
controllers: whether a page has a user is answered by `$ctx->auth()`, not by
the container. See [Http context](http-context.md).

## Permissions

A successful authentication establishes a principal **plus its capabilities**:

```php
$auth->authenticate($user, [Permission::AdminAccess, 'users.read']);
```

`Kaly\Auth\PermissionSet` is intentionally closed: `allows()`, `any()`,
`all()`. No roles, wildcards, inheritance or denies. Permissions accept
strings or string-backed enums and are reloaded on every request, never stored
in the session — a revoked permission applies to the very next request.

A permission opens the door of a use case; it is not the business rule:

```php
if (!$auth->allows(Permission::AppointmentsWrite)) {
    throw new ForbiddenException();
}
// ...then the use case still checks practice, ownership and state
```

Object-level rules ("may this doctor edit that appointment?") belong to the
use case or domain policy, so CLI or internal callers cannot bypass them.

## Session login and logout

The session keeps a **reference** to the identity (usually the user id), never
the principal object. `Kaly\Auth\SessionAuthentication` owns that lifecycle
and nothing else — it depends on the session and the authentication, never on
the whole context:

```php
$sessionAuth->login(
    $ctx->session(),
    $ctx->auth(),
    (string) $user->id,
    $user,
    $permissions,
);

$id = $sessionAuth->identifier($ctx->session()); // ?string

$sessionAuth->logout($ctx->session(), $ctx->auth());
```

Login regenerates the session id before writing (fixation protection);
logout removes only the `_auth` key — flash messages, carts and wizards
survive — then clears the identity and regenerates the id. Destroying the
whole session stays an explicit application choice.

Restoring the user on each request is a small application middleware, because
only the application can load its users:

```php
final class ResolveUser implements MiddlewareInterface
{
    public function __construct(
        private Users $users,
        private SessionAuthentication $sessionAuth,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $ctx = HttpContext::from($request);
        $id = $this->sessionAuth->identifier($ctx->session());

        if ($id !== null) {
            $identity = $this->users->authenticationIdentity($id);

            if ($identity !== null) {
                $ctx->auth()->authenticate($identity->principal, $identity->permissions);
            }
        }

        return $handler->handle($request);
    }
}
```

`ResolveUser` identifies, it protects nothing: public pages keep working for
visitors while knowing the connected user. Protection is a second, separate
step — and its failure mode is the application's choice (HTML redirects to
the login, an API answers 401):

```php
if (!$ctx->auth()->isAuthenticated()) {
    throw new RedirectException($ctx->url('admin:login'));
}

if (!$ctx->auth()->allows(Permission::AdminAccess)) {
    throw new ForbiddenException();
}
```

Kaly ships no `RequireAuthenticated`: eight lines of application middleware
express redirect-vs-401 better than any configuration.

A login controller validates credentials through the application, then
delegates the lifecycle and redirects (303):

```php
public function post(LoginInput $input): never
{
    $user = $this->users->findByEmail($input->email);

    if ($user === null || !password_verify($input->password, $user->passwordHash)) {
        throw new ValidationException('Invalid credentials');
    }

    $this->sessionAuth->login(
        $this->ctx->session(),
        $this->ctx->auth(),
        (string) $user->id,
        $user,
        $permissions,
    );

    $this->redirectToRoute('admin:dashboard');
}
```

## Module middleware

Identification and protection compose through the module topology. A module
declares the middlewares every route it resolves carries:

```php
return static function (Module $module): void {
    $module
        ->mount('admin')
        ->middleware(ResolveUser::class)
        ->middleware(RequireAdmin::class);
};
```

Scope middlewares merge in front of the route, group, controller and action
middlewares (each runs once), and they cover the module claims as well —
a custom resolver cannot forget the guard. There is no `except()` mechanism:
the login page lives outside the guarded scope instead of being carved out
of it:

```text
Public/Auth scope:  Csrf
                    ├── GET  /login
                    └── POST /login

Admin scope:        ResolveUser → Csrf → RequireAdmin
                    └── /admin/*
```

The topology tells the security story. See [Modules](modules.md) and
[Routing](routing.md).

## HTTP credentials

`Kaly\Http\Authorization` parses the `Authorization` header once, so nobody
does it by hand: lowercase scheme, strict Base64 Basic split on the first
colon, opaque Bearer token. Validating the credential stays applicative —
a Bearer middleware loads its principal and calls
`$ctx->auth()->authenticate($apiClient)` on every request, with no session.

A missing or invalid credential is a `401` with a mandatory challenge:

```php
throw new UnauthorizedException('Bearer');
throw new UnauthorizedException('Basic realm="Staging"');
```

401 means "authenticate", 403 means "authenticated but forbidden".

For staging areas, `Kaly\Auth\Middleware\BasicAccessMiddleware` is a pure
HTTP gate: it checks Basic credentials and continues, or answers 401 with a
challenge built from its realm. It never establishes an application identity,
and Basic auth belongs behind HTTPS:

```php
new BasicAccessMiddleware(username: 'stage', password: 's3cret', realm: 'Staging')
```

## CSRF

State-changing requests authenticated by a browser cookie need CSRF
protection. `Kaly\Http\Csrf\Csrf` is a small session primitive — one ASCII
secret per session, masked differently on every render — with `token()`,
`validate()`, `refresh()` and `clear()`. `CsrfMiddleware` enforces it on
unsafe methods, reading the `_csrf` body field first, then the
`X-CSRF-Token` header for fetch requests. A failure is a 403
(`InvalidCsrfTokenException`).

Mount it where the topology needs it. Login forms need it too
(login-CSRF), without requiring authentication:

```php
$module
    ->mount('auth')
    ->middleware(CsrfMiddleware::class);
```

Templates use the reserved `csrf` variable — the raw value, escaped by the
engine — next to the reserved `auth` variable. See [Views](views.md):

```html
<input type="hidden" name="{{ csrf.fieldName }}" value="{{ csrf.token }}">
```

```twig
{% if auth.isAuthenticated %}
    Hello {{ auth.principal.name }}
{% endif %}
```

Bearer APIs carry no automatic credential, so they mount no CSRF middleware;
a cookie-authenticated SPA does. The middleware never guesses from a present
`Authorization` header: the scope it is mounted on is the policy.

## Method override

HTML forms only send GET and POST. `MethodOverrideMiddleware` tunnels a POST
submission to `PUT`, `PATCH` or `DELETE` before routing, so resolvers, guards
and CSRF all see the effective method. It is opt-in and disabled by default:

```php
$app->middleware()->incoming(MethodOverrideMiddleware::class);
```

```html
<form method="post" action="...">
    <input type="hidden" name="_method" value="DELETE">
    <input type="hidden" name="_csrf" value="{{ csrf.token }}">
</form>
```

Only real POST requests are overridable; the `_method` field is read from
true HTML submissions (never JSON — JSON clients use the
`X-HTTP-Method-Override` header, or better the real method). Two conflicting
targets, or a target outside the whitelist, is a 400
(`InvalidMethodOverrideException`).

CSRF sees the overridden method: `POST` with `_method=DELETE` still requires
a valid token.

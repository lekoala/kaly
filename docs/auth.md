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
in the session — a revoked permission applies to the very next request. An
empty permission is refused wherever it appears, when the set is built as well
as when it is asked about: `''` names nothing, so it is a caller bug rather
than a permission.

A permission opens the door of a use case; it is not the business rule:

```php
if (!$auth->allows(Permission::OrdersWrite)) {
    throw new ForbiddenException();
}
// ...then the use case still checks ownership and state
```

Object-level rules ("may this clerk edit that order?") belong to the
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

Login regenerates the session id before writing (fixation protection).
Logout removes the authentication reference and the CSRF secret, while
preserving unrelated session data such as flash messages, carts and wizards;
it then clears the identity and regenerates the id. Because both transitions
drop the secret, a token issued under the previous identity stops validating.
Destroying the whole session stays an explicit application choice.

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

## Access policy recipe

Three layers, three owners:

```text
Authentication      = who is here + capabilities
Application guard   = HTTP access policy
Use case / domain   = business rule on the resource
```

`Authentication` stays a state-and-reading primitive: it never decides what
an anonymous request deserves. That policy lives in an application guard,
where "what if anonymous?" has exactly one answer per app — a web app
redirects, an API throws a Bearer 401, another app may answer otherwise:

```php
final class AdminGuard
{
    public function check(HttpContext $ctx): void
    {
        $auth = $ctx->auth();

        if (!$auth->isAuthenticated()) {
            throw new RedirectException(
                $ctx->url('admin:login'),
            );
        }

        if (!$auth->allows(Permission::AdminAccess)) {
            throw new ForbiddenException();
        }
    }
}
```

The guard stays stateless — the request context is special to controllers
and must never be captured in a shared service, where it would go stale
across requests. The middleware then stays almost declarative:

```php
$this->guard->check(HttpContext::from($request));

return $handler->handle($request);
```

Kaly will not grow `denyAccessUnlessGranted()` and family: the guard you can
read is the whole abstraction.

A login controller validates credentials through the application, then
delegates the lifecycle and redirects (303):

```php
public function post(LoginInput $input): never
{
    $user = $this->users->findByEmail($input->email);

    if ($user === null || !password_verify($input->password, $user->passwordHash)) {
        $validator = new Validator();
        $validator->add(new Violation(
            field: null,
            code: 'invalid_credentials',
            messageId: 'invalid_credentials',
            fallback: 'Invalid credentials',
            domain: 'auth',
        ));
        throw new ValidationException($validator->result());
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

When the password is only the first step, the login branches into a
pending challenge instead of calling `login()` right away: the session
stays anonymous until every requirement is satisfied. That flow —
including the anonymous-session, cleanup, anti-replay and enrollment
rules — lives under [Second factors](two-factor.md).

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
Public/Auth scope:  ResolveUser → Csrf
                    ├── GET  /login
                    └── POST /login

Admin scope:        ResolveUser → Csrf → RequireAdmin
                    └── /admin/*
```

The login page resolves the identity like any public page — the header can
still show who is connected — but asks for no permission. The topology tells
the security story. See [Modules](modules.md) and [Routing](routing.md).

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
and it refuses at construction what could never produce a valid request or a
valid header: an empty username, an empty password, or a realm carrying
characters that cannot appear in an HTTP header. Both credentials are compared
on every request, so a valid username is not distinguishable from an invalid
one by response time. An empty realm and a realm containing HTAB stay valid.
Basic auth belongs behind HTTPS:

```php
new BasicAccessMiddleware(username: 'stage', password: 's3cret', realm: 'Staging')
```

A browser replays cached Basic credentials automatically, so a Basic-protected
UI that submits state-changing requests needs CSRF just like a
cookie-authenticated form; an explicit API client does not. See
[Security](security.md).

## CSRF

State-changing requests authenticated by a browser cookie need CSRF
protection. It is documented under [Security](security.md): the `Csrf`
session primitive, `CsrfMiddleware` (login-CSRF included, without requiring
authentication), and the reserved `csrf` template variable next to `auth`:

```twig
{% if auth.isAuthenticated %}
    Hello {{ auth.principal.name }}
{% endif %}
```

Bearer APIs carry no automatic credential, so they mount no CSRF middleware;
a cookie-authenticated SPA does.

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

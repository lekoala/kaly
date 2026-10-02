---
layout: default
title: Auth
nav_order: 12
---
# Auth

Authentication is identification (who is this?), authorization is permission
(may they enter?). Kaly keeps both in the routed band, before the controller —
never inside the action.

## Protecting an area

Scope a middleware to the routes that need it. Table groups apply whatever
the action, `#[Middleware]` on a base controller covers a whole area:

```php
$routes->prefix('/admin')->middleware(StaffOnly::class)->group(function (Routes $routes): void {
    $routes->get('/orders', [OrderController::class, 'index']);
});
```

```php
use Kaly\Router\Middleware;

#[Middleware(StaffOnly::class)]
abstract class AdminController extends AbstractController {}
```

A guard short-circuits with a response and never calls `$handler->handle()`:

```php
final class StaffOnly implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $user = HttpContext::from($request)->session()->get('user');
        if ($user === null) {
            return new Response(403, [], 'denied');
        }
        return $handler->handle($request);
    }
}
```

A declared guard always runs or fails loudly: an unknown class throws when the
table compiles. See [Routing](routing.md).

## Logging in

Write the session, then redirect (303). The kernel commits the session onto the
redirect response, so nothing is lost:

```php
public function login(LoginInput $input): never
{
    $user = $this->users->authenticate($input->email, $input->password);
    $this->ctx()->session()->set('user', $user->id);
    $this->redirectToRoute('shop:account');
}
```

Read the actor back from the session in guards and controllers
(`$this->ctx()->session()->get('user')`). Object-level rules ("may this user
operate on that order?") belong to the use case or domain policy, not to the
HTTP layer — so CLI or internal callers cannot bypass them. See
[Http context](http-context.md).


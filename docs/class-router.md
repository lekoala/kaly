# Class Router

> Automatically maps requests to controllers

## Usage

The router tries to find a matching controller based on the request.

The first part of the request will be matched to a module if possible. If none is matched,
the default module is used.

By default, controllers lives in the `Controller` namespace and ends with `Controller`.

If the controller does not exists, the default controller is called (`IndexController`).

## Calling actions

Any unmatched part will be passed on the action. Only public methods can be called.

Methods argument are validated by their type.

...$rest style variables will fetch all remaining parameters, eg

    /some/url/with/a/lot/of/params/

Works with

```php
class SomeController
{
    function url(...$args) { ... }
    ...
```

Extra parameters will otherwise throw errors by default.

## Debug

The controller will throw exceptions with very clear messages on what's wrong if routing fails. It's
up to the caller's class to properly handle these.

## Trailing slash

By default, the class router automatically redirect to a path with a trailing slash

## Double index

By default, you cannot call /index/index/ (index method on index controller) as this causes duplicate urls.
It will redirect to /index/ instead.

/index/index/someparam/ is allowed.

## Examples

    /

Will be matched to App\Controller\IndexController::index

    /contact/

Will be matched to App\Controller\ContactController::index if it exists.
Otherwise, it would map to App\Controller\IndexController::contact.

    /contact-send/

Will be matched to App\Controller\ContactSendController::index if it exists.
Otherwise, it would map to App\Controller\IndexController::contactSend.

    /contact/index/

Is invalid for App\Controller\ContactController::index if it exists.
Otherwise, it would map to App\Controller\IndexController::contact and pass "index" as the first arg.

    /admin/login/

Will be matched to Admin\Controller\LoginController::index if the Admin module exists.
Otherwise it would map to App\Controller\AdminController::login.

    /app/contact/

Will be matched to App\Controller\AppController::index
Default module is never matched as a segment.

    /contact/send/

Will be matched to App\Controller\ContactController::send

    /contact/send/myemail@test.com/

Will be matched to App\Controller\ContactController::send and pass myemail@test.com as a parameter
if defined.

    /user/read/1/

Will be matched to App\Controller\UserController::read and pass 1 if you have a function accepting a number
as the first parameter.

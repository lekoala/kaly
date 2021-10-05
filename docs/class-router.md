# Class Router

> Automatically maps requests to controllers

## Usage

The router tries to find a matching controller based on the request.

The first part of the request will be matched to a module if possible. If none is matched,
the default module is used.

By default, controllers lives in the `Controller` namespace and ends with `Controller`.

If the controller does not exists, the default controller is called (`IndexController`).

## Calling actions

Any unmatched part will be passed on the action. Only public methods accepting a `ServerRequestInterface` object can be called.

Methods argument are validated by their type.

...$rest style variables will fetch all remaining parameters, eg

    /some/url/with/a/lot/of/params/

Works with

```php
class SomeController
{
    function url(ServerRequestInterface $request, ...$args)
    {
    }
```

Extra parameters will otherwise throw errors by default.

## Debug

The controller will throw exceptions with very clear messages on what's wrong if routing fails. It's
up to the caller's class to properly handle these.

## Trailing slash

By default, the class router automatically redirect to a path with a trailing slash

## Double index and duplicated urls

By default, you cannot call /index/index/ (index method on index controller) as this causes duplicate urls.
It will redirect to /index/ instead.

/index/index/someparam/ is allowed.

In the same spirit, you cannot call index method directly or only have the default locale.

## Routing process

This is how the process of routing works. Segments of the path are examined one by one.

-   First, we check for a locale based on allowed locales.
    -   In a multilingual setup, the locale is required, except for the base path / where the default locale is assumed
-   We check for a module. This is optional, default module is assumed if nothing matches.
-   We check for a controller. If no segment (passing / or /module/), index is assumed.
    -   Note: calling other methods on index require using the /index prefix (eg: /index/myaction)
-   We look for an action. It will look for 'actionMethod' or 'action'. If none, index or \_\_invoke is assumed
-   We collect remaining parameters based on action signature

Finally, the router will also compute a default template path based on the matched module/class/action.

## Dispatching actions

The router itself will not dispatch the action. Instead, it will return an array of parameters
that you can use to do it yourself.

These parameters are:

-   module
-   controller
-   action
-   params
-   locale
-   template

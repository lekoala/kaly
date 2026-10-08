# views and i18n

## Views

A controller can return a view such as:

```php
return View::of('@booking/appointment', ['appointment' => $appointment]);
```

Kaly deliberately keeps `RendererInterface` minimal:

```php
render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
```

Page data and the reserved render environment travel separately. Do not merge
capabilities into application view data, and do not make application code depend
on engine-specific layout concepts at the Kaly renderer boundary.

Layout inheritance and composition belong to the selected template engine and
templates.

Use the renderer already chosen by the application. Do not add Twig, Latte, or
kaly-tpl merely because Kaly supports it.

## Reserved render capabilities

Kaly provides request-bound render capabilities such as:

```text
i18n
url
asset
auth
csrf
csp
```

Treat these as framework-provided capabilities, not application view data.

Do not attempt to pass reserved names as controller data: whenever an environment
is provided, page data containing one of them is rejected with a `Kaly\Ex` before
the engine runs. A standalone `render()` without an environment injects no
capability and may use such names as ordinary locals.

With kaly-tpl, application locals remain isolated between templates: pass
application data explicitly to partials and layouts when they need it.

With Kaly's kaly-tpl 0.2 adapter, the six reserved capabilities are forwarded as
`sharedData` for the current render, reaching layouts, includes and `each()`
templates. Do not pass them again in layout/partial data or register them as
engine globals: a collision is rejected. The names are `i18n`, `url`, `asset`,
`auth`, `csrf` and `csp`, reserved by `Kaly\Core\RenderEnvironment`.

Do not introduce process-global request state to make template variables
implicitly available.

With Twig, `include ... only` drops the parent context: pass the needed helpers
explicitly. Never install per-request globals on the shared Twig environment.

## Internationalization

Translation is request-locale aware.

Do not mutate a shared translator to change the current locale.

Use the request-bound/localized translator supplied by Kaly or an explicit
`LocalizedTranslator`.

For an application-designed `/{locale}/...` topology, project the placeholder
with the explicit `RouteLocale` middleware; `{locale}` is never reserved
globally.

Keep translation identifiers and parameters separate from already rendered
human text where delayed translation is required.

Do not add a global `t()` helper to application or domain code.

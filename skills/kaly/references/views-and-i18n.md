# views and i18n

## Views

A controller can return a view such as:

```php
return View::of('@booking/appointment', ['appointment' => $appointment]);
```

Kaly deliberately keeps `RendererInterface` minimal:

```php
render(string $template, array $data = []): string
```

Do not make application code depend on engine-specific layout concepts at the
Kaly renderer boundary.

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

Do not attempt to override reserved names from controller data.

With kaly-tpl, application locals remain isolated between templates: pass
application data explicitly to partials and layouts when they need it.

With Kaly's kaly-tpl 0.2 adapter, the six reserved helpers are forwarded as
`sharedData` for the current render, reaching layouts, includes and `each()`
templates. Do not pass them again in layout/partial data or register them as
engine globals: kaly-tpl rejects collisions with shared data. The names are
defined by `Kaly\View\RenderVariables`.

Do not introduce process-global request state to make template variables
implicitly available.

## Internationalization

Translation is request-locale aware.

Do not mutate a shared translator to change the current locale.

Use the request-bound/localized translator supplied by Kaly or an explicit
`LocalizedTranslator`.

Keep translation identifiers and parameters separate from already rendered
human text where delayed translation is required.

Do not add a global `t()` helper to application or domain code.

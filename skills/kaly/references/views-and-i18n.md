# views and i18n

## Views

A controller can return a view such as:

```php
return View::of('@booking/appointment', ['appointment' => $appointment]);
```

The `RendererInterface` from `lekoala/kaly-contracts` 1.1 has a single method.

```php
render(string $template, array $data = [], array $shared = []): string
```

Page data and the shared render capabilities travel separately. Do not merge
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

Keep controller view data free of these names. `ViewResponder` rejects a
collision with a `Kaly\Ex` before the engine runs. When calling `render()`
directly, a name present in both arrays resolves to the value in `$shared`.
With an empty `$shared` array, these names can be used as ordinary locals.

With kaly-tpl, application locals remain isolated between templates: pass
application data explicitly to partials and layouts when they need it.

The Kaly adapter uses the per-render API introduced in kaly-tpl 0.4. It forwards
the capabilities as `sharedData` for the current render, reaching layouts,
includes and `each()` templates. Do not pass them again in layout or partial
data or register them as engine globals, since the engine rejects those
collisions.

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

---
layout: default
title: Views
nav_order: 9
---
# Views

Kaly is renderer agnostic. The core only exposes:

- `Kaly\View\View` — a controller result: `View::of('@module/template', $data)`;
  `withStatus(404)` answers with another status code;
- `Kaly\View\RendererInterface` — `render(string $template, array $data = []): string`;
- optional capabilities `TemplateLocatorInterface` (`has()`) and `TemplatePathRegistryInterface` (`setPath()`).

Controllers return a `View`; the dispatcher delegates to `Kaly\Core\ViewResponder`,
which renders it through the configured `RendererInterface`. The same responder
renders application error views selected by `Kaly\Core\ErrorViewInterface` (see
[application error pages](application-structure.md#custom-html-error-pages)).
An `array` is returned as JSON (200), a
`Kaly\Http\JsonResult::of($data, 201)` as JSON with its status code and headers,
a `string` as HTML. If no renderer is configured, returning a `View` throws.

Every render also receives an `i18n` variable: a translator bound to the locale of the
current request. It is reserved, so view data never overrides it. See [i18n](i18n.md).

Every render also receives an `url` variable: `$url('shop:product', $params)`
generates an url for the locale of the current request. It is reserved too.

Every render also receives an `asset` variable: `$asset('app.css')` or
`$asset('@admin/admin.js')` generates an asset url. It is reserved too.
See [Assets](assets.md).

Every render also receives an `auth` variable: a read-only view over the
request authentication (`isAuthenticated`, `principal()` — null when
anonymous — and `allows()`). Mutating the authentication from a template is
impossible by construction. See [Auth](auth.md).

Every render also receives a `csrf` variable: `csrf.token()` renders the
request token (escaped by the engine), `csrf.fieldName` and
`csrf.headerName` name the field and the fetch header. Rendering a view
without using `csrf.token()` never creates the session. See
[Security](security.md).

The reserved names are defined by `Kaly\View\RenderVariables`. With kaly-tpl 0.2,
the adapter forwards `i18n`, `url`, `asset`, `auth`, `csrf` and `csp` as per-render
shared data: they are available in the page, layouts, includes and `each()` templates.
Page data stays local to the root template; pass any page values needed by a layout
or partial explicitly:

```php
$v->layout('layout', ['title' => $title]);
```

Do not pass the reserved helpers again through layout or partial data, or configure
them as engine globals: kaly-tpl rejects names that collide with shared data.

With Twig, `include ... only` drops the parent context on purpose: pass the
helpers the partial needs explicitly, eg
`{{ include('partials/nav.html.twig', {i18n: i18n, url: url}, with_context = false) }}`.
Never install per-request globals on the shared Twig environment to work around
this: a global mutated for one request leaks into the next one in a worker.

Layout and template inheritance stay the engine's business: `RendererInterface` is
deliberately limited to `render(string $template, array $data)`. Twig, Latte and
kaly-tpl each have their own composition mechanism, and the contract must not embed
one engine's feature. A controller chooses the view; the template chooses its layout.

## Choosing a renderer

| Engine   | When                             | Adapter                             |
|----------|----------------------------------|-------------------------------------|
| Latte    | recommended template language    | `Kaly\View\Adapter\LatteRenderer`   |
| kaly-tpl | lightweight native PHP templates | `Kaly\View\Adapter\KalyTplRenderer` |
| Twig     | existing Twig ecosystem          | `Kaly\View\Adapter\TwigRenderer`    |

Adapters are optional: install the engine you want and register the adapter as the
`RendererInterface`. Kaly never abstracts engine-specific features (extensions, filters,
sandbox, blocks): configure those directly on the engine.

### Latte (recommended)

```bash
composer require latte/latte
```

```php
use Kaly\View\Adapter\LatteRenderer;
use Kaly\View\RendererInterface;
use Latte\Engine;
use Latte\Loaders\FileLoader;

$latte = new Engine();
$latte->setLoader(new FileLoader(__DIR__ . '/views'));

$definitions->set(RendererInterface::class, new LatteRenderer($latte));
```

### kaly-tpl (lightweight native PHP)

```bash
composer require lekoala/kaly-tpl:^0.2
```

```php
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

$engine = new ViewEngine(__DIR__ . '/views');

$definitions->set(RendererInterface::class, new KalyTplRenderer($engine));
```

### Twig (also supported)

```bash
composer require twig/twig
```

```php
use Kaly\View\Adapter\TwigRenderer;
use Kaly\View\RendererInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$twig = new Environment(new FilesystemLoader(__DIR__ . '/views'));

$definitions->set(RendererInterface::class, new TwigRenderer($twig));
```

## Module templates

When the configured renderer implements `TemplatePathRegistryInterface`, Kaly registers each
module `templates/` directory under the module name, so templates can be referenced as
`@Module/template`. This is automatic with `kaly-tpl`, Latte (`LatteRenderer` resolves
namespaces through its loader) and Twig (`TwigRenderer` registers them on a
`FilesystemLoader`, which is the default setup). A renderer backed by another loader
(a Twig `ArrayLoader`, a Latte `StringLoader`) cannot register paths: use plain names
with those.


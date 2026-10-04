---
layout: default
title: Views
nav_order: 8
---
# Views

Kaly is renderer agnostic. The core only exposes:

- `Kaly\View\View` — a controller result: `View::of('@module/template', $data)`;
  `withStatus(404)` answers with another status code;
- `Kaly\View\RendererInterface` — `render(string $template, array $data = []): string`;
- optional capabilities `TemplateLocatorInterface` (`has()`) and `TemplatePathRegistryInterface` (`setPath()`).

Controllers return a `View`; the dispatcher renders it through the configured
`RendererInterface`. An `array` is returned as JSON (200), a
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
[Auth](auth.md).

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
composer require lekoala/kaly-tpl
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


---
layout: default
title: Views
nav_order: 9
---
# Views

Kaly is renderer agnostic. The core only exposes:

- `Kaly\View\View` — a controller result: `View::of('@module/template', $data)`;
  `withStatus(404)` answers with another status code;
- `Kaly\View\RendererInterface` — `render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string`;
- optional capabilities `TemplateLocatorInterface` (`has()`) and `TemplatePathRegistryInterface` (`setPath()`).

Controllers return a `View`; the dispatcher delegates to `Kaly\Core\ViewResponder`,
which renders it through the configured `RendererInterface`. The same responder
renders application error views selected by `Kaly\Core\ErrorViewInterface` (see
[application error pages](application-structure.md#custom-html-error-pages)).
An `array` is returned as JSON (200), a
`Kaly\Http\JsonResult::of($data, 201)` as JSON with its status code and headers,
a `string` as HTML. If no renderer is configured, returning a `View` throws.

## Page data and render environment

A render carries two distinct things:

```text
$data         page data owned by the application (a user, a product...)
$environment  reserved capabilities contributed by Kaly for this render
```

They travel separately, so page data can never silently replace a capability.
The six capabilities are reserved for the whole render:

| Name | Capability |
| --- | --- |
| `i18n` | a translator bound to the locale of the request |
| `url` | an url generator bound to the locale of the request |
| `asset` | an asset url generator |
| `auth` | a read-only view over the request authentication |
| `csrf` | the CSRF token and the field names |
| `csp` | the CSP nonce of the response |

Whenever an environment is provided, page data containing one of these names is
rejected with a `Kaly\Ex` before the engine runs. A standalone `render()` without
an environment injects no capability and stays free to use such names as
ordinary locals.

The renderer receives `Kaly\View\RenderEnvironmentInterface`, which exposes the
capabilities as variables and enforces the reserved names. The typed
`Kaly\Core\RenderEnvironment` is built by `ViewResponder` and composes the
domains; it holds references, not copies, so CSRF and CSP stay lazy and `auth`
observes the request authentication. Each render gets its own environment, and an
adapter never stores it on a shared engine: two renders never leak a locale, a
token or a nonce into each other.

## Engine syntax

The guarantee is common; the expression is the engine's. The capabilities are
injected as variables the engine understands.

### kaly-tpl

`$v` keeps its native meaning (composition, escaping, formatting); the
capabilities are ordinary variables:

```php
<h1><?= $v->e($i18n->translate('account.title')) ?></h1>
<a href="<?= $v->url($url('account')) ?>">Account</a>
<?= $v->e($asset('app.css')) ?>
```

`$url('account')` generates the url, `$v->url(...)` escapes it for a URL
context: keep the two distinct.

Use `$v->date($date)`, `$v->time($date)` and `$v->datetime($date)` for localized
display. Keep persistence and serialization formats at their own boundaries;
the view formatter handles presentation styles such as `short` and `medium`.

### Twig

`TwigRenderer` installs a small Kaly extension that exposes the capabilities
idiomatically, and injects the rest as variables:

{% raw %}
```twig
{{ 'account.title'|trans }}
{{ url('account') }}
{{ asset('app.css') }}
{{ csrf.token() }}
{{ csp.nonce() }}
{% if auth.allows('account.edit') %}...{% endif %}
```
{% endraw %}

The extension is stateless: every helper reads the capability from the context of
the current render. Configure the Twig environment completely before wrapping it
in `TwigRenderer`, which is the last step of the configuration; a conflicting
`trans`, `url` or `asset` already provided by another extension is a
configuration error rather than a silent override.

### Latte

The capabilities are typed render parameters:

```latte
<h1>{$i18n->translate('account.title')}</h1>
<a href="{$url('account')}">Account</a>
{$asset('app.css')}
```

## Composition and partials

Layout and template inheritance stay the engine's business: `RendererInterface`
is deliberately limited to `render()`. Twig, Latte and kaly-tpl each have their
own composition mechanism, and the contract must not embed one engine's feature.
A controller chooses the view; the template chooses its layout.

With kaly-tpl 0.2 the capabilities are forwarded as per-render shared data:
they are available in the page, layouts, includes and `each()` templates. Page
data stays local to the root template; pass any page values needed by a layout
or partial explicitly:

```php
$v->layout('layout', ['title' => $title]);
```

Do not pass the reserved capabilities again through layout or partial data, or
register them as engine globals: a collision is rejected.

With Twig, `include ... only` drops the parent context on purpose, so the
capabilities are gone too. Pass the ones the partial needs explicitly, eg
{% raw %}`{% include 'partial.twig' with {url: url} only %}`{% endraw %}. A Kaly helper whose
capability is missing fails with an explicit error instead of recovering a
hidden parent context. Never install per-request globals on the shared Twig
environment: a global mutated for one request leaks into the next in a worker.

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

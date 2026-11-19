---
layout: default
title: Views
nav_order: 9
---
# Views

Kaly is renderer agnostic. The core only exposes:

- `Kaly\View\View` — a controller result: `View::of('@module/template', $data)`;
  `withStatus(404)` answers with another status code;
- `Kaly\View\RendererInterface` — `render(string $template, array $data = [], array $shared = []): string`,
  provided by [`lekoala/kaly-contracts`](https://github.com/lekoala/kaly-contracts) like the task
  interfaces: an adapter for any engine can be written and tested against the contract alone;
- optional capabilities `TemplateLocatorInterface` (`has()`) and `TemplatePathRegistryInterface` (`setPath()`).

Controllers return a `View`; the dispatcher delegates to `Kaly\Core\ViewResponder`,
which renders it through the configured `RendererInterface`. The same responder
renders application error views selected by `Kaly\Core\ErrorViewInterface` (see
[application error pages](application-structure.md#custom-html-error-pages)).
An `array` is returned as JSON (200), a
`Kaly\Http\JsonResult::of($data, 201)` as JSON with its status code and headers,
a `string` as HTML. If no renderer is configured, returning a `View` throws.

## Page data and shared capabilities

A render carries two distinct things, as two separate arrays:

```text
$data    page data owned by the application (a user, a product...)
$shared  the capabilities Kaly contributes to the whole render
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

`ViewResponder` rejects page data containing one of these names with a
`Kaly\Ex` before the engine runs. The reserved names are simply the keys of the
shared array: the typed `Kaly\Core\RenderEnvironment` is built by `ViewResponder`
and composes the domains; it holds references, not copies, so CSRF and CSP stay
lazy and `auth` observes the request authentication. Each render gets its own
capabilities, and an adapter never stores them on a shared engine: two renders
never leak a locale, a token or a nonce into each other.

A renderer called directly, outside the responder, applies the contract rule
instead: when a name exists in both `$data` and `$shared`, the shared value
wins, and `$shared` may be empty. What a composed template scope (layout,
partial) receives stays the engine's own scoping rule: Twig's
`include ... only`, for instance, drops the parent context on purpose.

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

A kaly-tpl engine formats with the locale configured on the engine
(`$engine->locale(...)`), which suits an application that serves one locale. A
multilingual worker keeps that default and derives the formatter of each render
from the locale of the request:

```php
use Kaly\Tpl\DefaultValueFormatter;
use Kaly\Tpl\ValueFormatter;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

$formatters = [
    'fr' => new DefaultValueFormatter('fr_BE', 'EUR', new DateTimeZone('Europe/Brussels')),
    'nl' => new DefaultValueFormatter('nl_BE', 'EUR', new DateTimeZone('Europe/Brussels')),
];

$definitions->set(RendererInterface::class, new KalyTplRenderer(
    $engine,
    formatterFor: function (string $locale): ?ValueFormatter {
        return $formatters[$locale] ?? null;
    },
));
```

The callback receives the locale the `i18n` capability carries and returns the
formatter for that render only — kaly-tpl 0.4 threads it through the page, its
partials and its layouts, and the shared engine is never mutated. Returning
`null` (an unknown locale) keeps the engine default. Keep the formatter
instances in the map: `DefaultValueFormatter` reuses its ICU formatters across
calls, an advantage a fresh instance per render would lose.

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

The kaly-tpl adapter forwards the capabilities as per-render shared data.
They are available in the page, layouts, includes and `each()` templates. Page
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
composer require lekoala/kaly-tpl:^0.4
```

```php
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

$engine = new ViewEngine(__DIR__ . '/views');

$definitions->set(RendererInterface::class, new KalyTplRenderer($engine));
```

Mago's PHP analyzer can also check kaly-tpl's `.phtml` files. Add `phtml` to
`[source].extensions` alongside `php`, include your template directories in
`[source].paths`, and supply `@var` annotations or let kaly-tpl generate them
during a debug render. See the [Mago recipe for kaly-tpl views](https://github.com/lekoala/kaly-mago#analyze-kaly-tpl-views-with-mago).

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

---
layout: default
title: Assets
nav_order: 20
---
# Assets

Kaly knows where the browser-ready assets of the application live, produces
their URL, and can publish them. It knows nothing of npm, Bun, importmaps,
bundling or minification.

> Asset directories contain browser-ready files. Kaly preserves their relative
> paths and contents. It never parses, compiles, bundles, minifies, rewrites,
> or resolves frontend dependencies.

## Layout

```text
assets/                  static files of the application (namespace `app`)
modules/Admin/assets/    static files of the Admin module (namespace `admin`)
modules/Site/assets/     static files of the Site module (namespace `site`)
```

A namespace is the decamelized module name. In templates and code, assets are
addressed as `app.css` (the `app` namespace) or `@admin/admin.js` (a module
namespace):

```php
$assets->url('app.css');
$assets->url('@admin/admin.js');
```

{% raw %}
```twig
<link rel="stylesheet" href="{{ asset('app.css') }}">
<script type="module" src="{{ asset('@admin/admin.js') }}"></script>
```
{% endraw %}

Because the relative tree is preserved from source to URL, relative references
keep working with zero rewriting:

```js
import "./components/dialog.js";
```

```css
background-image: url("./images/logo.svg");
```

Bare specifiers such as `import "@lekoala/calendar"` are not Kaly's problem:
if Bun or another tool resolves them, its output must land browser-ready in
`assets/` (or `modules/*/assets/`).

## Development

The application opts in to serving sources directly, typically in debug mode:

```php
if ($app->isDebug()) {
    $app->middleware()->incoming(AssetServer::class);
}
```

```text
/_assets/app/app.js      -> assets/app.js
/_assets/admin/a.css     -> modules/Admin/assets/a.css
```

Responses carry `Cache-Control: no-store`. Edit a file, reload, done. The
server is never enabled automatically: like everything in Kaly, the framework
provides the mechanism and the application decides its runtime.

## Production

A build step publishes the sources to an immutable versioned directory:

```php
// bin/build.php
$publisher = new AssetPublisher($sources, $paths->publicDir());
$publisher->publish();
```

```text
public/assets/
  .version
  8af319c42d/
    app/
    admin/
    site/
```

URLs become `/assets/8af319c42d/app/app.js`, served with `Cache-Control:
public, max-age=31536000, immutable`. There is no framework CLI: call
`publish()` from `bin/build.php`, a composer script, a Makefile or CI.

The version is the explicit argument (or `APP_ASSETS_VERSION`) when given,
otherwise a deterministic content hash (SHA-256 over sorted paths and
contents, truncated to 10 chars). The publisher writes
`public/assets/.version` so the runtime knows which version was built; in
production `Assets::url()` reads `APP_ASSETS_VERSION` first, then that file,
and fails explicitly when nothing was published:

```text
Assets have not been published. Set APP_ASSETS_VERSION or run your asset publisher.
```

Version resolution is lazy: an application that never calls `asset()` never
fails.

> An explicit asset version must change whenever published contents change.
> An existing destination is considered already published and left untouched:
> a given version is immutable.

## Rules

- Symlinks are not supported: the publisher fails loudly on them.
- Path segments starting with `.` (`.env`, `.htaccess`, `.git/`) are never
  published and never served.
- Executable extensions (`.php`, ...) are refused, mirroring `FileServer`.
- Generating a URL never checks the filesystem; verification belongs to the
  dev server and the publisher.

## Reference

| Class | Role |
| --- | --- |
| `Kaly\Asset\AssetsInterface` | `url(string $asset): string` |
| `Kaly\Asset\Assets` | dev (`/_assets/...`) and prod (`/assets/<version>/...`) URLs |
| `Kaly\Asset\AssetSources` | namespace => directory map shared by all three consumers |
| `Kaly\Asset\AssetPublisher` | build-time copy + `.version` |
| `Kaly\Asset\Middleware\AssetServer` | dev serving, opt-in |


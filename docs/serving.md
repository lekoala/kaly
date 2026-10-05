---
layout: default
title: Serving
nav_order: 19
---
# Serving locally

Kaly needs no CLI and no development server of its own: plain PHP already
serves pretty urls. The skeleton (not the framework) owns this:

```text
my-app/
├── public/
│   ├── index.php    boots App and runs the request
│   └── router.php   static files through, everything else to index.php
└── composer.json
```

```php
// public/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/index.php';
```

```json
{
    "scripts": {
        "serve": "php -S 127.0.0.1:8000 -t public public/router.php"
    }
}
```

```bash
composer serve
```

When PHP's built-in server receives a PHP file, it uses it as the router:
returning `false` serves a static file directly, anything else runs the
application. Production works the same way with any server that forwards
unknown paths to `index.php` (nginx `try_files`, Apache `FallbackResource`).

## Assets in development

Published assets live under `public/assets/` and are served as plain static
files. Unpublished sources (`assets/`, `modules/*/assets/`) can be served
directly in debug mode through `Kaly\Asset\Middleware\AssetServer` on
`/_assets/*`:

```php
if ($app->isDebug()) {
    $app->middleware()->incoming(AssetServer::class);
}
```

See [Assets](assets.md).


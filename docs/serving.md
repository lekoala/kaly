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

## Conditional responses

`Kaly\Http\ConditionalRequest::isNotModified()` answers whether an existing,
selected representation is unchanged for a GET or HEAD request. Select the
representation and check access first; it must otherwise produce a 200 response.
Errors and redirects take precedence over cache revalidation.

The application supplies a complete HTTP ETag (`"abc"` or `W/"abc"`), a last
modification instant, or both. It owns validator generation, representation
variants and cache policy. The helper does not read files or construct responses.

`If-None-Match` takes precedence whenever the header is present, even when it
is malformed. Valid lists use weak ETag comparison; commas inside quoted tags
are preserved. A standalone `*` matches the existing representation even without
an ETag. A wildcard mixed with other values is invalid and never matches.
Malformed values return false without falling back to `If-Modified-Since`.

Without `If-None-Match`, a valid `If-Modified-Since` matches when the supplied
last modification instant is no later than the requested instant, at second
precision. Multiple dates and impossible calendar dates are ignored. All three
HTTP date formats are accepted.

Inject `ConditionalRequest` into the controller. Its `ClockInterface` dependency
uses Kaly's existing clock binding and controls the RFC 850 two-digit year rule
(timestamps more than 50 years in the future roll back a century). No additional
registration is needed.

This helper is not a complete precondition evaluator: it returns false for
methods other than GET and HEAD and does not produce 412 responses. It also
returns false when `If-Match` or `If-Unmodified-Since` is present. Applications
that evaluate those preconditions first may remove them from a request copy
before invoking the helper after a successful evaluation.

Build a 304 response with an empty body. Preserve `ETag`, `Date`, `Vary`,
`Cache-Control` and `Expires` whenever they would be sent on the corresponding
200 response; include `Content-Location` when applicable and retain useful
`Last-Modified` metadata. Apply the same validators and cache policy on both paths.
For files, evaluate the condition before calling `FileResponseFactory::create()`
to avoid opening the file stream on the 304 path. Pass the request method to
the file factory so HEAD remains bodyless on the 200 path too.

For example, an application's file action can share headers between both paths:

```php
use Kaly\Http\ConditionalRequest;
use Kaly\Http\FileResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DownloadDocument
{
    public function __construct(
        private DocumentAccess $documents,
        private ConditionalRequest $conditional,
        private FileResponseFactory $files,
        private ResponseFactoryInterface $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $id): ResponseInterface
    {
        // Application service: resolve the document and enforce read access.
        $document = $this->documents->readable($id);
        $etag = $document->etag(); // Complete HTTP ETag for this representation.
        $modified = $document->updatedAt();
        $headers = [
            'ETag' => $etag,
            'Last-Modified' => $modified->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T'),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ];

        $response = $this->conditional->isNotModified($request, $etag, $modified)
            ? $this->responses->createResponse(304)
            : $this->files->create($document->path(), method: $request->getMethod());

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
```

`DocumentAccess` is application code. It must resolve errors, authorization and
any higher-priority preconditions before reaching revalidation. The application
must ensure its emitted `Last-Modified` is no later than response origination.
The response's `Date` is supplied by the HTTP server, or by application code using
its injected clock. Any explicitly supplied `Date`, `Vary`, `Expires` or
`Content-Location` also belongs in the shared headers.

For a JSON action, use the same helper before serialization. Return a PSR-7
response for the empty 304 and a `JsonResult` for the modified representation:

```php
public function show(ServerRequestInterface $request, string $id): JsonResult|ResponseInterface
{
    $product = $this->products->readable($id);
    $headers = [
        'ETag' => $product->jsonEtag(),
        'Cache-Control' => 'private, max-age=0, must-revalidate',
        'Vary' => 'Accept',
    ];

    if ($this->conditional->isNotModified($request, $headers['ETag'])) {
        $response = $this->responses->createResponse(304);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    return JsonResult::of($product->toArray(), headers: $headers);
}
```

Here `JsonResult` is `Kaly\Http\JsonResult`. Validators must describe the selected
representation, including any negotiated variant or content encoding.

These rules follow [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html#section-13)
and [RFC 9111](https://www.rfc-editor.org/rfc/rfc9111.html#section-4.3.4).

## Who serves what

- `AssetServer` serves asset *sources* in development (`/_assets/*`).
- `FileServer` (and the plain-PHP `router.php` above) expose `public/`.
- Application routes stay ordinary routes: a controller can answer
  `/sitemap.xml` or `/robots.txt` like any other path — no new public server
  in Asset is needed for those.


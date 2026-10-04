---
layout: default
title: Security
nav_order: 13
---
# Security

How Kaly applications draw browser security boundaries: CSRF protection for
cookie-authenticated requests, and CSP nonces shared between templates and
the response header. Identity, session authentication and permissions live
under [Auth](auth.md); this page covers HTTP-level browser mechanics. In
both cases the policy stays applicative — Kaly provides the primitive and
the pipeline slot, never the rule.

## CSRF

State-changing requests authenticated by a browser cookie need CSRF
protection. `Kaly\Http\Csrf\Csrf` is a small session primitive — one ASCII
secret per session, masked differently on every render — with `token()`,
`validate()`, `refresh()` and `clear()`. Login and logout rotate the secret
through `SessionAuthentication`, so a token never survives an identity
change. `CsrfMiddleware` enforces it on
unsafe methods, reading the `_csrf` body field first, then the
`X-CSRF-Token` header for fetch requests. A failure is a 403
(`InvalidCsrfTokenException`).

Mount it where the topology needs it. Login forms need it too
(login-CSRF), without requiring authentication:

```php
$module
    ->mount('auth')
    ->middleware(CsrfMiddleware::class);
```

Templates use the reserved `csrf` variable — the raw value, escaped by the
engine. Rendering a view without calling `csrf.token()` never creates the
session. See [Views](views.md):

```html
<input type="hidden" name="{{ csrf.fieldName }}" value="{{ csrf.token }}">
```

Bearer APIs carry no automatic credential, so they mount no CSRF middleware;
a cookie-authenticated SPA does. The middleware never guesses from a present
`Authorization` header: the scope it is mounted on is the policy.

## CSP nonces

A strict Content Security Policy authorizes inline scripts and styles through
a nonce both sides agree on: the template writes `<script nonce="...">` and
the response carries `script-src 'nonce-...'`. `Kaly\Http\Csp\Csp` is that
shared value — one lazily generated nonce per response, owned by the
`HttpContext` so templates and the outgoing header cannot disagree:

```php
$nonce = $ctx->csp()->nonce(); // stable for the whole cycle
```

Nothing is generated until first use, and two cycles never share a nonce.
The same nonce authorizes scripts and styles; there is deliberately a single
one per response.

Templates use the reserved `csp` variable, which is the very same object:

```html
<script nonce="{{ csp.nonce }}" src="{{ asset('app.js') }}"></script>
```

The policy itself is an outgoing middleware away — the band exists precisely
for response headers that must be there whatever produced the response. Only
HTML documents need a CSP, and even error pages deserve one, so the condition
reads the response rather than the route:

```php
use Kaly\Http\ContentType;
use Kaly\Http\MediaType;

$app->middleware()->outgoing(
    static function (
        ResponseInterface $response,
        HttpContext $ctx,
    ): ResponseInterface {
        $nonce = $ctx->csp()->nonce();

        return $response->withHeader('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'nonce-{$nonce}' 'strict-dynamic'",
            "object-src 'none'",
            "base-uri 'none'",
        ]));
    },
    when: static function (ResponseInterface $response): bool {
        $media = MediaType::parse($response->getHeaderLine('Content-Type'));

        return $media !== null && (string) $media === ContentType::HTML;
    },
    always: true,
);
```

Roll out a new policy with `Content-Security-Policy-Report-Only` first, then
enforce it. Never add nonces by post-processing rendered HTML: a tag injected
by an attacker before that step would receive a valid nonce as well. Nonces
belong where trusted markup is produced — the template.

CSP applies with or without HTTPS; only HSTS is HTTPS-only.

## What Kaly does not bundle

There is no `CspMiddleware`, no policy builder and no security-headers
bundle. Four lines composing a nonce and a header do not need a framework
wrapper, and directives like HSTS or `frame-ancestors` depend on the
deployment. They remain explicit outgoing middlewares until real applications
demonstrate a shared construction worth providing.

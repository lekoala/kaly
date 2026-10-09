---
layout: default
title: Security
nav_order: 15
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

{% raw %}
```html
<input type="hidden" name="{{ csrf.fieldName }}" value="{{ csrf.token }}">
```
{% endraw %}

Validating a token against a request with no existing session returns `false`
without creating storage or emitting a session cookie. Generating or refreshing
a token writes its secret and creates the session, even after earlier anonymous
reads. Clearing a secret from a missing session does not create one.

Whether CSRF applies is a question of who sends the request, not of which
`Authorization` scheme is present. A browser replays cached Basic credentials
inside their protection space (RFC 7617), so a Basic-protected UI sending
`POST`/`PUT`/`DELETE` needs CSRF like any cookie-authenticated form. An
explicit API client — curl, service-to-service — carries no ambient browser
credential and generally does not. The middleware never guesses from a present
`Authorization` header: the scope it is mounted on is the policy.

## Caching authenticated responses

A response built for an authenticated user must not be stored or replayed by
shared caches. Kaly has no automatic rule for this — a public page can touch
the session (flash messages) without being private — so the policy is an
outgoing middleware:

```php
use Kaly\Core\HttpContext;
use Psr\Http\Message\ResponseInterface;

$app->middleware()->outgoing(
    static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface =>
        $response->withHeader('Cache-Control', 'no-store'),
    when: static fn(ResponseInterface $response, HttpContext $ctx): bool =>
        $ctx->auth()->isAuthenticated(),
);
```

`no-store` alone is enough: it forbids storage by private and shared caches
alike, so `private` is redundant. It applies to every authenticated response,
not only HTML — an authenticated JSON payload carries just as much private
data.

Cover the logout answer explicitly. Logout clears the identity before the
response is emitted, so `isAuthenticated()` is already `false` there, yet the
answer still belongs to the authenticated flow. The application knows its
logout path, so key the outgoing middleware on it:

```php
when: static fn(ResponseInterface $response, HttpContext $ctx): bool =>
    str_ends_with($ctx->request()->getUri()->getPath(), '/logout'),
```

Cookie sessions get no implicit protection here: unlike a request carrying
`Authorization`, HTTP does not restrict shared caches for cookie-authenticated
traffic (RFC 9111). The header is the application's to add.

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

{% raw %}
```html
<script nonce="{{ csp.nonce }}" src="{{ asset('app.js') }}"></script>
```
{% endraw %}

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

## Application secret and signatures

`Kaly\Crypto\Secret` is the root secret of the application: at least 32 random
bytes, configured as base64url text in `APP_SECRET`. Kaly never generates a
missing secret. Create one once per environment and keep it stable across the
instances that must verify each other's signatures:

```bash
php -r 'require "vendor/autoload.php"; echo Kaly\Crypto\Secret::generate(), PHP_EOL;'
```

`App` resolves `Secret` from `APP_SECRET` on first use, so an application that
signs nothing needs no secret. A missing or invalid value fails at that point
with a `Kaly\Ex` naming the variable. Bind your own `Secret` to load it from
elsewhere (a secrets manager, a file outside the web root).

The raw secret never leaves the object. Neither the secret nor a derived key
is stored in an instance property, so `var_dump()`, `print_r()` and Symfony
VarDumper (`d()`) never show them; serialization and cloning are refused. Each
purpose gets its own key through HKDF-SHA256.
`Kaly\Crypto\Hmac` signs and verifies with such a key:

```php
use Kaly\Crypto\Hmac;
use Kaly\Crypto\Secret;

// Usually built once in a factory of the module's config.php
$codes = new Hmac($container->get(Secret::class), 'auth-verification:v1');
$context = json_encode([$memberId, $recipient, $code], JSON_THROW_ON_ERROR);

$hash = $codes->sign($context);              // store this, never the code
$valid = $codes->verify($context, $storedHash);
```

- **One purpose per use.** A signature made for `form-protection:v1` never
  verifies as `auth-verification:v1`. Bump the version in the purpose to
  invalidate every signature of that use at once.
- **Bind the context.** Encode every field that matters unambiguously, eg as a
  JSON list, never by concatenating strings.
- **Compare in PHP.** Load the record, then call `verify()`: the comparison is
  constant time, and a stored format can evolve. Avoid looking signatures up
  with SQL equality.
- **A signature is not a policy.** Expiry, attempt limits and single use stay
  the application's rules.
- **Rotation.** Changing `APP_SECRET` invalidates every signature. That is fine
  for short-lived codes; a long-lived stored format should carry a version so a
  key ring can be added later.

### Signature contract

Signatures can be stored or cross deployments, so their construction is a
compatibility contract that Kaly keeps stable:

- the key is HKDF-SHA256 of the secret, 32 bytes, with
  `info = "kaly.hmac:" . purpose` and no salt;
- the signature is HMAC-SHA256 of the message bytes under that key;
- the output is base64url without padding (43 characters).

Kaly owns this construction: changing it requires an explicit version or a
migration strategy for persisted signatures, and frozen reference vectors in
the test suite guard it. The version inside the purpose (`form-protection:v1`)
belongs to the application: bump it to change your own protocol without any
change to the primitive.

Short secrets such as six-digit codes need an HMAC rather than a plain hash: a
leaked SHA-256 of a code is reversed by trying a million values, while the HMAC
also needs the secret. Encryption is out of scope: use libsodium or a dedicated
library such as CipherSweet, with its own keys.

## What Kaly does not bundle

There is no `CspMiddleware`, no policy builder and no security-headers
bundle. Four lines composing a nonce and a header do not need a framework
wrapper, and directives like HSTS or `frame-ancestors` depend on the
deployment. They remain explicit outgoing middlewares until real applications
demonstrate a shared construction worth providing.

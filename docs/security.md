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

`CsrfMiddleware` only checks unsafe methods, so it covers every write only if
no write answers a safe one. Conventional routing keeps that invariant: an
action without a verb suffix answers `GET` and `HEAD` only, a write is named
`savePost` or `removeDelete` (see [Routing](routing.md#the-convention)). This is
not CSRF protection by itself: `savePost()` still needs the middleware when the
request is authenticated by a cookie. Every public method of a controller,
including inherited and trait methods, is a conventional action: keep helpers
and setters `protected`. A declared route chooses its methods explicitly; never
map a write on `GET`.

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
- **Rotation.** Replacing `APP_SECRET` makes existing signatures derived from
  it fail against the new secret. Keeping old verification keys can preserve
  them without changing their format; see [Secret rotation](#secret-rotation).

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

### Trust boundary

An HMAC authenticates the signed bytes to parties holding the shared key. It
does not identify which holder produced them: a verifier with the key can also
forge signatures. Keep the secret outside the storage whose contents need
protection, and use separate secrets for independent trust domains. Distinct
purposes separate derived keys, but compromise of the root secret compromises
every purpose derived from it.

Sign every field that affects the intended operation, including its context
(eg tenant or recipient), with an unambiguous encoding. Verify the exact signed
bytes before reconstructing objects or invoking application code. For a queue,
an allowlist of task types and argument validation constrain what can execute;
they do not authenticate who submitted valid arguments.

Signing is useful when someone can alter stored messages without access to the
signing secret or a trusted dispatcher. This depends on access boundaries,
whether the storage is SQLite, PostgreSQL or another backend. It does not
prevent deletion, replay of an intact signed message, or abuse of an authorized
producer. Authorization at execution, idempotency, expiry and attempt limits
remain separate rules.

### Secret rotation

Use a dedicated `Secret` for a feature that needs its own rotation schedule,
supplied by an application factory; `APP_SECRET` stays the default root. To
rotate without invalidating stored signatures, sign with the new key and keep
verifying with the old one:

```php
$signature = $new->sign($message);
$valid = $new->verify($message, $signature) || $old->verify($message, $signature);
```

Across producers, consumers and long-lived workers, deploy in three stages:

1. Keep signing with the old key; deploy verification of both keys everywhere,
   including instances a rollback could bring back.
2. Switch producers to the new key, still verifying both.
3. Drop the old key once no message signed with it must remain usable: delayed
   and failed tasks, retries, records restorable from backups.

Rotation needs no key identifier, encoding change or new purpose. Re-sign
stored data only after verifying it under a trusted key, otherwise you
authenticate whatever is stored. A compromised key is not rotated but revoked:
accepting it also accepts an attacker's signatures, so the affected records
must be rejected or rebuilt from a trusted source.

### Signatures made by others

`Hmac` implements Kaly's signature contract using an explicitly supplied
`Secret`, normally resolved from `APP_SECRET`. A webhook from a third party
(GitHub, Stripe, a support desk) follows the provider's contract: its shared
secret, its encoding (often hex, sometimes with a `sha256=` prefix or a
timestamp), its message. Use the provider's SDK when it has one, otherwise
compute the signature with `hash_hmac()` over the **raw** request body, never
a re-encoded parsed payload, and compare with `hash_equals()`:

```php
$secret = Env::getString('SUPPORT_WEBHOOK_SECRET');
$expected = hash_hmac('sha256', (string) $request->getBody(), $secret);
$valid = hash_equals($expected, $request->getHeaderLine('X-Signature'));
```

Keep the provider secret in its own environment variable, never in logs, and
reject the request before parsing it when the signature does not match. The
signatures the application sends back, eg a callback url it later receives,
are its own again and belong to `Hmac` with a dedicated purpose.

## Behind a reverse proxy

A proxy that terminates TLS talks plain http to PHP: the request scheme is
`http`, so the session and application cookies, which are `Secure` on https
requests unless the `CookiePolicy` decides, would lose the flag. The client
ip would be the proxy's. `Kaly\Http\Middleware\TrustedProxy` restores the
scheme, host, port and client ip from the `X-Forwarded-*` headers, only when
the peer (`REMOTE_ADDR`) is one of the configured proxies:

```php
use Kaly\Http\Middleware\TrustedProxy;

$definitions->set(TrustedProxy::class, static fn(): TrustedProxy => new TrustedProxy(['10.0.0.0/8', 'fd00::/8']));
$app->middleware()->incoming(TrustedProxy::class, priority: -100);
```

The proxy must overwrite these headers instead of appending to what the
client sent: the first `X-Forwarded-Proto`/`Host`/`Port` value wins. The
client ip is the nearest `X-Forwarded-For` hop that is not a trusted proxy,
and `HttpContext::clientIp()` returns it. Redirects issued by the router are
relative, they never reflect the Host. When https is the only way in, set
`secure: true` on the `CookiePolicy` rather than relying on detection.

## What Kaly does not bundle

There is no `CspMiddleware`, no policy builder and no security-headers
bundle. Four lines composing a nonce and a header do not need a framework
wrapper, and directives like HSTS or `frame-ancestors` depend on the
deployment. They remain explicit outgoing middlewares until real applications
demonstrate a shared construction worth providing.

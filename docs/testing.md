---
layout: default
title: Testing
nav_order: 16
---
# Testing

`Kaly\Test\TestClient` drives the application in memory: PSR-7 request in,
PSR-7 response out. No socket, no server, no browser state.

```php
use Kaly\Test\TestClient;

$app = App::create(__DIR__)->boot();
$client = TestClient::for($app);

$client->get('/hello')
    ->assertStatus(200)
    ->assertHeader('Content-Type', 'application/json')
    ->assertJson(['hello' => 'world']);
```

`TestClient::for()` needs the container, so the app must be booted.

Use `App::default()` when the test targets the behavior of a recommended Kaly
application; use `App::create()` with an explicit `routing()` configuration
when the routing convention itself is a precondition of the test.

`request()` is the primitive, the verbs are sugar over it:

```php
$client->request('POST', '/orders', ['json' => [...]]);
$client->get('/search', ['query' => ['q' => 'kaly']]);
$client->post('/login', ['form' => ['user' => 'ada']]);
```

The `$options` vocabulary is closed: `headers`, `query`, `form`, `files`, `json`, a raw
`body`, explicit `cookies`, and `maxRedirects` for automatic redirect
following — a single body kind per request. Anything else fails loudly instead
of being silently ignored.

For uploads, pass PSR-7 `UploadedFileInterface` objects in `files`, optionally
alongside `form`:

```php
$client->post('/admin/media', [
    'form' => ['title' => 'Photo'],
    'files' => ['image' => $uploadedFile, 'gallery' => [$firstFile, $secondFile]],
]);
```

Nested arrays and upload errors are preserved. The client sets the parsed body
and uploaded files directly on the server request; it does not encode a multipart
body or generate a multipart content type. `files` cannot be combined with `json`
or a raw `body`. Construct the uploaded objects using your PSR-7 implementation.

`TestResponse` offers `assertStatus()`, `assertHeader()`, `assertLocation()`,
`assertBody()` and `assertJson()`: plain PHPUnit assertions, so failures are real
PHPUnit errors with the usual diffs. `response()` goes back to plain PSR-7.

## Stateful flows: cookies and redirects

The client keeps the response cookies sufficient for application and session
testing — not a full HTTP cookie jar (RFC 6265): names and values persist,
attributes (domain, path, secure, expiry) are neither enforced nor stored.
Explicit `cookies` win over the stored ones; `clearCookies()` resets the store
and `cookies()` inspects it.

```php
$client->post('/login', ['form' => ['user' => 'ada']])->assertStatus(303);
$client->followRedirect()->assertBody('user:42');

// Or in one go, with a bounded automatic following:
$client->post('/login', ['form' => ['user' => 'ada'], 'maxRedirects' => 5])
    ->assertBody('user:42');
```

303 (and 301/302 on POST) become GET; 307/308 replay the method, the body and
the parsed body and uploaded file objects
(streams cannot be replayed and fail loudly). Query-only and relative
locations resolve against the last request — keeping its origin, so a later
absolute same-origin redirect is not mistaken for cross-origin; cross-origin
locations are
refused, and loops past the limit fail instead of hanging.
When a redirect changes the request to GET, form data and files are discarded.
Replayed uploads reuse the same objects, so files already moved by the application
are not recreated.

Sessions across requests need a provider that outlives the cycle:
`Kaly\Test\MemorySessionProvider` keeps the data while the client jar carries
the id. Bind it explicitly before booting the tested app —
`ArraySessionProvider` stays for isolated cycles and never persists silently:

```php
$app = App::create(__DIR__)
    ->configure(static function (Definitions $di): void {
        $di->rebind(SessionProviderInterface::class, new MemorySessionProvider());
    })
    ->boot();
```

## Password fixtures

Use the same injected `Kaly\Auth\PasswordHasher` for fixtures, seeders and
login code, with a cheap bcrypt policy in the test composition:

```php
use Kaly\Auth\PasswordHasher;
use Kaly\Core\App;
use Kaly\Di\Definitions;

$app = App::create(__DIR__)
    ->configure(static function (Definitions $di): void {
        $di->set(PasswordHasher::class, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
    })
    ->boot();

$passwords = $app->container()->get(PasswordHasher::class);
$initializer->seedAdministrator(
    email: 'admin@example.test',
    passwordHash: $passwords->hash('secret'),
);
```

`set()` declares the policy when it was previously only autowired. If the
application already declares a production policy in module configuration or
an earlier configure hook, replace that declaration with `rebind()` instead:

```php
$di->rebind(PasswordHasher::class, new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
```

Avoid production-cost `password_hash()` calls in test setup. Bcrypt cost 4
produces ordinary salted hashes compatible with `password_verify()`; each call
gets a fresh salt. Fixture setup and login must resolve the same configured
dependency. Tests of the application's hashing policy should exercise its
actual production configuration. No environment-dependent cost or hash cache
is needed.

For tests that only need an authenticated identity, an application helper can
call `SessionAuthentication::login()` with its user and permissions. Keep tests
of the login form and credential validation on the real login flow. Kaly leaves
the user lookup in that helper to the application. See [Auth](auth.md).

## Testing boundaries

Two rules draw the boundary:

- `Kaly\Test` is provided by Kaly but is **not part of the production runtime**:
  PHPUnit stays a development dependency, and no assertion machinery of ours
  ever mirrors it.
- `TestClient` is an **in-process HTTP client, not a browser emulator**: no
  history, no DOM. What would need a browser stays out.


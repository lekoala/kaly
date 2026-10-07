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

`request()` is the primitive, the verbs are sugar over it:

```php
$client->request('POST', '/orders', ['json' => [...]]);
$client->get('/search', ['query' => ['q' => 'kaly']]);
$client->post('/login', ['form' => ['user' => 'ada']]);
```

The `$options` vocabulary is closed: `headers`, `query`, `form`, `json`, a raw
`body`, explicit `cookies`, and `maxRedirects` for automatic redirect
following — a single body kind per request. Anything else fails loudly instead
of being silently ignored.

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

303 (and 301/302 on POST) become GET; 307/308 replay the method and the body
(streams cannot be replayed and fail loudly). Query-only and relative
locations resolve against the last request; cross-origin locations are
refused, and loops past the limit fail instead of hanging.

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

Two rules draw the boundary:

- `Kaly\Test` is provided by Kaly but is **not part of the production runtime**:
  PHPUnit stays a development dependency, and no assertion machinery of ours
  ever mirrors it.
- `TestClient` is an **in-process HTTP client, not a browser emulator**: no
  history, no DOM. What would need a browser stays out.


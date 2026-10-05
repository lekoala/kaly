---
layout: default
title: Testing
nav_order: 15
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

The `$options` vocabulary is closed: `headers`, `query`, `form`, `json` and a raw
`body` — a single body kind per request. Anything else fails loudly instead of
being silently ignored.

`TestResponse` offers `assertStatus()`, `assertHeader()`, `assertLocation()`,
`assertBody()` and `assertJson()`: plain PHPUnit assertions, so failures are real
PHPUnit errors with the usual diffs. `response()` goes back to plain PSR-7.

Two rules draw the boundary:

- `Kaly\Test` is provided by Kaly but is **not part of the production runtime**:
  PHPUnit stays a development dependency, and no assertion machinery of ours
  ever mirrors it.
- `TestClient` is an **in-process HTTP client, not a browser emulator**: no
  redirect following, no history, no DOM. What would need a browser stays out.


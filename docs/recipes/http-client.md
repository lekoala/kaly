---
layout: default
title: HTTP client recipe
nav_order: 9
---
# HTTP client recipe

Kaly bundles no HTTP client. Calling a remote API is an infrastructure concern of
the application, and [PSR-18](https://www.php-fig.org/psr/psr-18/) already defines
the contract:

```php
interface ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface;
}
```

PSR-18 only sends a PSR-7 request. Building the request is the job of the PSR-17
factories, which Kaly already binds from the installed PSR-7 implementation (see
[PSR-7 implementation](../app.md#psr-7-implementation)). The application picks the
transport:

|             | [Buzz][buzz]             | [Symfony HttpClient][symfony]   |
|-------------|--------------------------|---------------------------------|
| Scope       | A small cURL transport   | Streaming, concurrency, retries |
| PSR-18      | Native `ClientInterface` | The `Psr18Client` adapter       |
| Concurrency | `MultiCurl`, callbacks   | Lazy native responses           |
| Testing     | A fake `ClientInterface` | `MockHttpClient`                |
| Evolution   | Stable, no new features  | Actively developed              |

[buzz]: https://github.com/kriswallsmith/Buzz
[symfony]: https://symfony.com/doc/current/http_client.html

Neither requires a framework. A reasonable default:

- **Buzz** when the application only sends PSR-7 messages and wants the smallest
  dependency;
- **Symfony HttpClient** when it has several integrations, parallel calls, or
  wants `MockHttpClient` and retries;
- **PSR-18 only, no transport** in a reusable library: depend on `ClientInterface`
  and let the application choose.

## Buzz

```bash
composer require kriswallsmith/buzz
```

`Buzz\Client\Curl` implements `ClientInterface` and builds its responses with the
PSR-17 response factory:

```php
use Buzz\Client\Curl;
use Kaly\Core\App;
use Kaly\Di\Definitions;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;

$app = App::create(dirname(__DIR__));
$app->configure(function (Definitions $definitions): void {
    $definitions->set(ClientInterface::class, static function (ContainerInterface $container): ClientInterface {
        return new Curl($container->get(ResponseFactoryInterface::class), [
            'timeout' => 10,
        ]);
    });
});
```

`Buzz\Client\FileGetContents` does the same without the curl extension.
`Buzz\Client\MultiCurl` also sends requests in parallel, through
`sendAsyncRequest()` with a callback and `flush()`; at that point, Symfony
HttpClient is usually the simpler choice.

## Symfony HttpClient

```bash
composer require "symfony/http-client:^7.4" psr/http-client
```

Symfony 7.4 supports PHP 8.3, like Kaly; Symfony 8 requires PHP 8.4.
`psr/http-client` is required separately: the component only needs it for its
PSR-18 adapter.

`Psr18Client` wraps the native client into a `ClientInterface`:

```php
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$definitions->set(ClientInterface::class, static function (ContainerInterface $container): ClientInterface {
    return new Psr18Client(
        HttpClient::create(['timeout' => 10]),
        $container->get(ResponseFactoryInterface::class),
        $container->get(StreamFactoryInterface::class),
    );
});
```

`HttpClient::create()` picks the cURL transport when available, and falls back
to the native PHP streams otherwise.

### PSR-18 or the native API

Through `sendRequest()`, Symfony behaves like any PSR-18 client: one request at a
time, the response fully received. Streaming, concurrency and the JSON helpers
belong to the native `HttpClientInterface`. An infrastructure class may depend on
it directly when it needs them, as any library used as an
[implementation detail](../application-composition.md#wrap-capabilities-not-libraries):

```php
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CatalogClient
{
    public function __construct(
        private HttpClientInterface $http,
    ) {}

    /**
     * @return array{users: array<mixed>, projects: array<mixed>}
     */
    public function snapshot(): array
    {
        // Both requests start before either response is read
        $users = $this->http->request('GET', 'https://api.example.com/users');
        $projects = $this->http->request('GET', 'https://api.example.com/projects');

        return ['users' => $users->toArray(), 'projects' => $projects->toArray()];
    }
}
```

```php
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

$definitions->set(HttpClientInterface::class, static fn(): HttpClientInterface => HttpClient::create(['timeout' => 10]));
```

Responses are lazy: the requests run concurrently, without promises, and the code
only blocks when it reads a result. Note that the two APIs report errors
differently (see below).

## Using the client

The code that calls a remote API depends on `ClientInterface` and the PSR-17
factories, never on the transport. When the application owns the capability, put
it behind its own port and translate the remote failures there, as described in
[Application composition](../application-composition.md):

```php
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class HttpWebhookSender implements WebhookSender
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    public function send(Webhook $webhook): WebhookResult
    {
        $request = $this->requests->createRequest('POST', $webhook->url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($webhook->payload, JSON_THROW_ON_ERROR)));

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            return WebhookResult::unreachable($e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return WebhookResult::delivered();
        }

        return WebhookResult::rejected($status);
    }
}
```

```php
$definitions->bind(WebhookSender::class, HttpWebhookSender::class);
```

`HttpWebhookSender` is autowired: switching from Buzz to Symfony only changes the
`ClientInterface` definition.

## Errors

- **A `4xx` or `5xx` is a response, not an exception.** PSR-18 only throws when no
  response could be obtained: `NetworkExceptionInterface` (DNS, connection,
  timeout), `RequestExceptionInterface` (an invalid request), and
  `ClientExceptionInterface` as their common parent. Check the status code.
- **The native Symfony API does throw** on `4xx` and `5xx` when the content is read
  with `getContent()` or `toArray()`. Pass `false` (`toArray(false)`) to inspect the
  status yourself.
- **Remote failures are not HTTP failures of the application.** A failing API must
  not leak as a `502` with the remote body: translate it at the infrastructure
  boundary, into a result or a plain `Kaly\Ex`, and let the application decide what
  the client of *your* application sees.

## Timeouts

PSR-18 has no timeout: it is a property of the transport, configured where the
client is built. Always set one. Without it, a remote API that hangs holds the PHP
worker for as long as `max_execution_time` allows, or forever under a worker
runtime, and enough hanging calls exhaust the pool for every user.

Keep calls to slow or unreliable APIs out of the request cycle when the response
does not need them: send them from a [task](../tasks.md) instead.

## Tests

Tests should not reach the network. Because the code depends on `ClientInterface`,
a fake is a few lines, whatever the transport:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $sent = [];

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(
        private array $responses,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return array_shift($this->responses) ?? throw new LogicException('No response left for ' . $request->getUri());
    }
}
```

With Symfony, `MockHttpClient` plays the same role for the native API, and can be
wrapped in a `Psr18Client` too:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

$client = new Psr18Client(new MockHttpClient([
    new MockResponse('{"ok":true}', ['http_code' => 200]),
]));
```

## Workers

A client holds no request state, so one shared instance serves every request.
Under a worker runtime (see [Runtime](../runtime.md)), it also keeps its
connections open between requests, which is usually what you want.

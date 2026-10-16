---
layout: default
title: Rate limiting recipe
nav_order: 8
---
# Rate limiting recipe

Kaly bundles no rate limiter. Counting attempts is simple, but the counters must be
shared and updated atomically across PHP processes: under PHP-FPM every request has
its own memory, and workers (FrankenPHP, RoadRunner) do not share theirs. An
in-memory counter only works for a single long-running process.

[Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html) provides the
policies (fixed window, sliding window, token bucket) and the storage interfaces.
Kaly provides the rest: PSR-15 middlewares, the client ip resolved by
[`TrustedProxy`](../security.md), and `TooManyRequestsException` for the `429`.

```bash
composer require "symfony/rate-limiter:^7.4" "symfony/cache:^7.4" "symfony/lock:^7.4"
```

Symfony 7.4 supports PHP 8.3, like Kaly; Symfony 8 requires PHP 8.4.

## One server: files and `flock`

On a single server, a file cache with `flock` locks is enough, no Redis or database
needed. The directory must be writable and shared by every PHP process of the
application:

```php
use Kaly\Core\App;
use Kaly\Di\Definitions;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

$app = App::create(dirname(__DIR__));
$app->configure(function (Definitions $definitions) use ($app): void {
    $definitions->set(ThrottleRequests::class, static function () use ($app): ThrottleRequests {
        $dir = $app->paths()->tempFor('rate-limit');

        return new ThrottleRequests(new RateLimiterFactory(
            ['id' => 'requests', 'policy' => 'sliding_window', 'limit' => 300, 'interval' => '1 minute'],
            new CacheStorage(new FilesystemAdapter('rate_limit', 0, $dir)),
            new LockFactory(new FlockStore($dir)),
        ));
    });
});
```

Without a `LockFactory`, two concurrent requests can read the same counter and both
pass: the lock is what makes the limit hold under load.

## The middleware

A middleware consumes one token per request, keyed by client ip, and throws
`TooManyRequestsException` once the limit is reached. The exception carries the `429`
status and the `Retry-After` header, and renders through the application error view
like a `404`:

```php
use Kaly\Http\Exception\TooManyRequestsException;
use Kaly\Http\RequestUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ThrottleRequests implements MiddlewareInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $limiters,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $limit = $this->limiters->create('ip:' . RequestUtils::getIp($request))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsException($limit->getRetryAfter());
        }

        return $handler->handle($request);
    }
}
```

The middleware only holds the factory, so one instance safely serves every request.
Register it in the incoming band, after `TrustedProxy` so the ip is the real client
one, or on the routes it protects:

```php
use Kaly\Http\Middleware\TrustedProxy;

$app->middleware()
    ->incoming(TrustedProxy::class, priority: 10)
    ->incoming(ThrottleRequests::class, priority: 20);

// in a route table
$routes->post('/contact', [ContactController::class, 'send'])->middleware(ThrottleContact::class);
```

Each limit is its own factory with its own `id`. A middleware resolved from a class
string is a single shared service, so a stricter limit for the contact form is a
second class (`ThrottleContact`, built the same way) rather than a shared counter.

## Logins, OTP codes and public forms

A middleware counts requests. A login must count attempts on an account, which only
the action knows. Consume **before** checking the password and reset on success:
reserving first means concurrent attempts cannot all slip through before the first
failure is recorded, and the reset means only failures add up.

```php
use Kaly\Http\Exception\TooManyRequestsException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class LoginThrottle
{
    public function __construct(
        private RateLimiterFactoryInterface $perAccount, // eg 5 per 15 minutes
        private RateLimiterFactoryInterface $perIp, // eg 50 per 15 minutes
    ) {}

    public function attempt(string $username, string $ip): void
    {
        foreach ([$this->account($username, $ip), $this->perIp->create('ip:' . $ip)] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsException($limit->getRetryAfter());
            }
        }
    }

    public function succeeded(string $username, string $ip): void
    {
        $this->account($username, $ip)->reset();
    }

    private function account(string $username, string $ip): LimiterInterface
    {
        return $this->perAccount->create('login:' . mb_strtolower(trim($username)) . '|' . $ip);
    }
}
```

Define it like `ThrottleRequests`, with two factories sharing the same storage and
locks but distinct ids. The action calls `attempt()` with
`RequestUtils::getIp($request)` before verifying the credentials, and `succeeded()`
after a successful login. Choose the keys deliberately:

- **Account and ip, not account alone.** A key on the username only lets anyone lock
  the administrator out by failing on purpose: on an application with a single admin
  account, a strict lockout is a denial of service. The per-ip limit, higher, stops a
  single client from spraying many usernames.
- **Normalize the identifier** the same way as the login does, or `Admin` and
  `admin` get separate counters.
- **OTP and second factor codes** are short and must be limited per pending login,
  not per ip only: key on the account or the challenge, and invalidate the challenge
  after a few failures rather than waiting for the window to expire.
- **Public forms** (contact, signup, password reset) are limited per ip, and per
  target address when the form sends email, so it cannot be used to flood one inbox.

Do not slow down failures with `sleep()`: under PHP-FPM a sleeping request still
holds a worker, and enough of them exhaust the pool for every user.

## Several servers: Redis

When several instances serve the application, files on one machine no longer see
the others' counters. Share the storage and the locks:

```php
use Kaly\Util\Env;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

$redis = RedisAdapter::createConnection(Env::getString('REDIS_DSN'));

$factory = new RateLimiterFactory(
    ['id' => 'requests', 'policy' => 'sliding_window', 'limit' => 300, 'interval' => '1 minute'],
    new CacheStorage(new RedisAdapter($redis, 'rate_limit')),
    new LockFactory(new RedisStore($redis)),
);
```

The middleware and `LoginThrottle` do not change: only the factory construction does.

## Limits

- **Storage grows with clients.** Every distinct key is a cache entry, and anonymous
  clients choose the keys. Redis expires them; the file cache only removes expired
  entries when pruned, so run `FilesystemAdapter::prune()` periodically (see the
  [cron recipe](cron.md)).
- **Storage failures.** If the cache or the locks are unavailable, the limiter
  throws. Decide whether a login should then fail closed (refuse) or a global limit
  fail open (let the request through), and catch accordingly.
- **Concurrency is not a rate.** A per-ip limit does not bound how many password
  hashes run at the same time across many ips. Size the PHP-FPM pool for the cost of
  a login, and keep hashing parameters reasonable.
- **Volumetric attacks** are stopped before PHP. Even a rejected request costs a
  PHP worker; limits in Nginx, Caddy or a CDN protect the server itself, and the
  application limits protect what only the application understands.

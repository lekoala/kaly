---
layout: default
title: Locking recipe
nav_order: 11
---
# Locking recipe

Kaly bundles no lock. A lock that prevents two imports from running at the same
time must be shared across PHP processes: under PHP-FPM every request has its own
memory, a cron job is another process, and workers (FrankenPHP, RoadRunner) do not
share theirs.

[Symfony Lock](https://symfony.com/doc/current/components/lock.html) is a standalone
component that provides the stores (files, Redis, PostgreSQL...) and the lock
semantics. Kaly provides the rest: the container, route middlewares and
`HttpException` for the client-facing `409`.

```bash
composer require "symfony/lock:^7.4"
```

Symfony 7.4 supports PHP 8.3, like Kaly; Symfony 8 requires PHP 8.4. The
[rate limiting recipe](rate-limiting.md) already installs it.

## The factory

Register one `LockFactory`. It holds no lock itself, so it is safely shared by every
request, task and command:

```php
use Kaly\Core\App;
use Kaly\Di\Definitions;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$app = App::create(dirname(__DIR__));
$app->configure(function (Definitions $definitions) use ($app): void {
    $definitions->set(LockFactory::class, static fn(): LockFactory => new LockFactory(
        new FlockStore($app->paths()->tempFor('locks')),
    ));
});
```

`FlockStore` works for every PHP process of a single server, as long as they share
the directory. The operating system releases a `flock` when the process dies, so a
crashed import never leaves a stale lock behind.

## Locking a use case

The lock belongs to the operation it protects, not to the way it is triggered. A use
case that takes the lock itself is protected from HTTP, the CLI, a cron job and a
[task](../tasks.md) alike:

```php
use Symfony\Component\Lock\LockFactory;

final readonly class ImportCatalog
{
    public function __construct(
        private LockFactory $locks,
        // ...
    ) {}

    public function __invoke(string $export): int
    {
        $lock = $this->locks->createLock('catalog-import', ttl: 300);
        if (!$lock->acquire()) {
            throw new ImportAlreadyRunning();
        }

        try {
            return $this->apply($this->plan($export));
        } finally {
            $lock->release();
        }
    }
}
```

`ImportAlreadyRunning` is an application exception. If the use case is only reached
over HTTP, it may extend `HttpException` with a `409`; otherwise keep it a plain
domain failure and let the HTTP adapter translate it.

The key is a plain string, so it can carry an identifier when only one operation per
object must run at a time: `'catalog-import:' . $catalogId`.

Create the lock in the method, never in a property: the service is shared, and a
lock stored on it would be shared by concurrent requests in a worker runtime.

### Expiration

A lock with a `ttl` expires on its own, so a stuck process cannot hold it forever.
The ttl must cover the real duration of the work; for a long import, refresh the
lock while it progresses:

```php
foreach ($plan->batches() as $batch) {
    $this->apply($batch);
    $lock->refresh();
}
```

Once expired, another process may acquire the lock while the first one is still
running. `$lock->isExpired()` tells the running process it lost it. `FlockStore`
does not expire at all: the lock lives until it is released or the process exits.

## Locking a route

A route middleware rejects concurrent requests before the controller runs:

```php
use Kaly\Http\Exception\HttpException;

final class ImportAlreadyRunning extends HttpException
{
    public function __construct()
    {
        parent::__construct('An import is already running', 409);
    }
}
```

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class LockCatalogImport implements MiddlewareInterface
{
    public function __construct(
        private LockFactory $locks,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $lock = $this->locks->createLock('catalog-import', ttl: 300);
        if (!$lock->acquire()) {
            throw new ImportAlreadyRunning();
        }

        try {
            return $handler->handle($request);
        } finally {
            $lock->release();
        }
    }
}
```

```php
$routes->post('/catalog/import', [CatalogController::class, 'import'])
    ->middleware(StaffOnly::class, LockCatalogImport::class);
```

Route middlewares run outermost first, so `StaffOnly` rejects an unauthorized
client with its `403` before the lock is even tried: an anonymous client can
neither receive a `409` nor hold the lock.

This only protects the requests that go through the route. Prefer locking the use
case when the same operation is also reachable from the CLI or a task. A per-object
key needs the route parameters, read from
`HttpContext::from($request)->route()->params`; the use case usually knows the
identifier more directly.

A response whose body is produced after the handler returns (a streamed export)
outlives the middleware: the lock is released before the body is sent. Lock inside
the stream callback instead.

## Several servers

When several instances serve the application, files on one machine no longer see the
others' locks. Use a shared store:

```php
use Kaly\Util\Env;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Symfony\Component\Lock\Store\RedisStore;

// Redis: expiring locks
$redis = new Redis();
$redis->connect(Env::getString('REDIS_HOST'));
$locks = new LockFactory(new RedisStore($redis));

// PostgreSQL: advisory locks, released when the connection closes
$locks = new LockFactory(new PostgreSqlStore(Env::getString('DATABASE_DSN')));
```

The use cases and the middleware do not change: only the factory construction does.

## Limits

- **Do not wait under PHP-FPM.** `acquire(true)` blocks until the lock is free, and a
  waiting request still holds a worker. Reject with a `409` and let the client retry;
  a CLI script or a worker may wait if that is the intended behavior.
- **A lock is not data integrity.** It prevents concurrent work, not invalid state:
  an expired lock, a store failure or a code path that forgot the lock lets a
  second writer through. A unique constraint or a transaction remains the final
  guarantee against a double booking or a duplicate row (see the
  [transactions recipe](transactions.md)).
- **Store failures.** If the store is unavailable, `acquire()` throws. Decide whether
  the operation should then fail closed (refuse) or run unprotected, and catch
  accordingly.
- **Overlapping cron runs** are the same problem: a [cron](cron.md) script calling a
  locked use case simply exits when the previous run is still going.

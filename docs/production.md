---
layout: default
title: Production
nav_order: 20
---
# Production

Most of the time spent by a classic request (PHP-FPM, one process per
request) is spent loading PHP classes, not running Kaly. Opcache settings, the
Composer autoloader and preloading are the levers that change it; Kaly itself
has no cache to warm. Measured by the Kaly performance lab on an Apple M1 Pro,
PHP 8.3, 20 modules, medians:

| Opcache | boot | first request |
| --- | ---: | ---: |
| file cache, timestamps validated | ~4.7 ms | ~1.4 ms |
| preloading | ~0.75 ms | ~0.15 ms |

With preloading, the boot grows by about 15 µs per module. A
[worker](runtime.md#worker-mode) pays the boot once per process instead.

## Composer

```bash
composer install --no-dev --classmap-authoritative
```

`--no-dev` also drops the `files` autoload entries of development packages,
which every request would otherwise include. `--classmap-authoritative` answers
every class from the generated classmap without touching the filesystem: declare
the modules under `autoload.psr-4` so their classes are part of it.

## Opcache

```ini
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
```

Size the limits to the application and its vendor directory, then check them
with `OpcacheStatus` below. JIT brings little to an HTTP application that
mostly waits on I/O.

With `validate_timestamps=0`, opcache never sees a deployed file: reset it on
each deploy by reloading PHP-FPM (`systemctl reload php8.3-fpm`). The CLI has
its own opcache, so `opcache_reset()` from a console command resets nothing
served by FPM. For atomic symlink deploys, also pass the resolved path to PHP
(nginx `$realpath_root`), or the old release stays cached under the symlink.

When a deploy cannot reload PHP-FPM, keep `opcache.validate_timestamps=1`:
opcache then checks each file at most every `opcache.revalidate_freq` seconds
(2 by default), a small cost that keeps deploys visible. Never set
`revalidate_freq=0` in production, it checks files on every request.

## Preloading

`opcache.preload` declares classes once, when the FPM pool starts: requests
then find them compiled and linked. `Kaly\Core\Preload` writes that script
from what the application actually loads: it boots the application, handles
the given GET paths, then lists every file the process included (classes,
module `config.php`, ...). Classes it did not see are autoloaded as usual.

The script compiles these files, it never executes them: a class that
depends on the bootstrap (a parent created by `class_alias()`, a declaration
guarded by a function or a constant) cannot fail the pool startup. Opcache
links the classes once every file is compiled; a class it cannot link is
skipped and loaded at runtime as usual.

```php
// bin/preload.php
use Kaly\Core\Preload;

$app = require dirname(__DIR__) . '/bootstrap.php';
Preload::record($app, ['/', '/health/'])->write(dirname(__DIR__) . '/preload.php');
```

```ini
opcache.preload=/var/www/app/preload.php
opcache.preload_user=www-data
```

A release, in the tree that is served:

```bash
composer install --no-dev --classmap-authoritative
php bin/preload.php                 # dedicated process, production environment
php -l preload.php
systemctl reload php8.3-fpm         # or restart: preload runs at pool start
```

- Record in a dedicated CLI process: the script lists every file that process
  included, so a process that also ran tests or another application would
  preload them too.
- Record in the tree that is deployed, after `composer install --no-dev` and
  with the production environment. Files below the script directory are
  written relative to it, so the tree can be built in one place and served
  from another.
- The paths run like real requests, through the middlewares and controllers:
  choose pages without side effects. A path answering 4xx or 5xx fails the
  recording. Without paths, the boot alone is recorded: that is most of the
  gain, and the safest choice.
- `write()` replaces the script atomically. Preloaded code still only changes
  when the pool starts again: generate the script for the release being
  deployed, then reload or restart PHP-FPM. A stale script still starts (a
  file that no longer exists is skipped, the classes depending on it are
  loaded at runtime) but preloads less, and a file with a syntax error stops
  the pool: keep the script and the release together.
- Preloaded classes stay in shared memory for the life of the pool, used or
  not (`preloadedMemoryBytes` in `OpcacheStatus` below). Recording what the
  application loads keeps that to the classes it needs; adding paths preloads
  their controllers too, for a little more memory and a faster first hit.
- At startup, opcache logs `Can't preload unlinked class` for each class it
  could not link: an anonymous class extending an application class, a class
  extending an alias, a conditionally declared trait (such as
  `Nyholm\Psr7\StreamTrait`). These classes are loaded at runtime as usual;
  the warnings are harmless.
- Never require `vendor/autoload.php` from a preload script, nor execute the
  files it lists: Composer `files` entries would run twice (`Cannot
  redeclare`), and a file depending on the bootstrap would fail the startup.
- `preload_user` is required when PHP-FPM starts as root. PHP does not
  support preloading on Windows.

## Workers

A worker (FrankenPHP, RoadRunner) boots the application once and keeps it in
memory: the boot leaves the per-request cost, and preloading only shortens the
worker startup. Opcache still matters, since every worker compiles the same
files. See [Runtime](runtime.md#worker-mode).

## Checking opcache

`Kaly\Debug\OpcacheStatus` reports what is wrong with the opcache serving the
current request: disabled or unreadable, full, restarting, memory, interned
strings or keys nearly exhausted, wasted memory, restarts caused by a full
cache and a low hit rate. Each problem names the directive to change.

Opcache memory belongs to the SAPI, so read it from a request served by the
FPM pool, never from a console command. Expose it on a route reserved to
operators:

```php
use Kaly\Debug\OpcacheStatus;

final class HealthController
{
    public function opcache(): array
    {
        $status = OpcacheStatus::current();
        return ['problems' => $status->problems(), 'metrics' => $status->metrics()];
    }
}
```

```json
{
    "problems": [],
    "metrics": {
        "enabled": true,
        "validateTimestamps": false,
        "cacheFull": false,
        "restartPending": false,
        "restartInProgress": false,
        "memoryUsedBytes": 54763520,
        "memoryFreeBytes": 213671936,
        "memoryWastedBytes": 0,
        "internedStringsUsedBytes": 9437184,
        "internedStringsFreeBytes": 24117248,
        "keysUsed": 431,
        "keysMax": 32531,
        "scripts": 412,
        "hits": 1832210,
        "misses": 412,
        "hitRatePercent": 100,
        "oomRestarts": 0,
        "hashRestarts": 0,
        "manualRestarts": 0,
        "startTime": 1792663200,
        "lastRestartTime": 0,
        "preloadedClasses": 268,
        "preloadedMemoryBytes": 4718592
    }
}
```

Sizes are in bytes, times are Unix timestamps (0 when opcache never
restarted), counters accumulate since opcache started: a restart or a low hit
rate may be old news, so monitoring should compare two readings rather than
alert on one. `validateTimestamps` and the preload metrics describe deployment
choices, not problems: validating timestamps is the right setting when deploys
do not reset the cache. Apply authentication or network restrictions to this
route: it discloses the server configuration.

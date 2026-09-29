# Benchmarks

> Relative numbers only: compare two commits on the same machine

## Protocol

```bash
composer bench                                          # the demo app, path /
php benchmarks/boot.php demos/app /simple-module/       # an app and a path
php benchmarks/boot.php --synthetic=100 /site/thing3/action2/
php benchmarks/boot.php --runs=50 --requests=5000       # more samples
```

Two execution models are measured, as medians:

- **fpm**: one process per request (autoload, boot, handle), like PHP-FPM. Opcache is
  warm through its file cache, timestamps validated.
- **worker**: boot once, then many requests in the same process, like FrankenPHP or
  RoadRunner. This process runs without opcache, so its boot includes compilation.

`--synthetic=N` generates an application of 5 modules sharing N controllers of 6
actions each, to check how the boot scales with the size of the application.

## Results

Windows 11, PHP 8.3, 2026-09-29.

| App                                                     | fpm autoload | fpm boot | fpm request | worker request |
|---------------------------------------------------------|--------------|----------|-------------|----------------|
| demo (5 modules)                                        | 2.7 ms       | 7.0 ms   | 2.9 ms      | 0.074 ms       |
| synthetic, 100 controllers                              | 2.8 ms       | 6.8 ms   | 2.6 ms      | 0.022 ms       |
| synthetic, 100 controllers, before hierarchical routing | 2.6 ms       | 21.5 ms  | 2.1 ms      | 0.07 ms        |

Before hierarchical routing, the boot scanned every controller of every module for
route attributes. It now only depends on the modules and their `config.php`, never on
the number of controllers. See [Runtime](runtime.md#no-cache-a-worker-instead) for why
Kaly optimizes with workers rather than with a cache.

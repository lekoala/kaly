# Kaly PHP framework

[![Latest Version](https://img.shields.io/packagist/v/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![Total Downloads](https://img.shields.io/packagist/dt/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![License](https://img.shields.io/packagist/l/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![PHP Version Require](https://img.shields.io/packagist/php-v/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly)

> A small modular PSR HTTP framework with convention-based routing and first-class dependency injection

Kaly is an opinionated but lightweight application framework built on PHP standards:

- **PSR based:** PSR-7 messages, PSR-11 container, PSR-15 middleware.
- **Dependency injection:** powered by [kaly-di](https://github.com/lekoala/kaly-di), autowiring and explicit definitions.
- **Convention-based routing:** no route file to maintain, controllers map to URIs.
- **Modular architecture:** each module has its own config, controllers, templates and assets.
- **Middleware support:** plain PSR-15 middleware, plus an optional generator style for before/after hooks.
- **Multilingual support:** built-in locale detection.
- **Renderer agnostic:** Latte (recommended), [kaly-tpl](https://github.com/lekoala/kaly-tpl) (lightweight native PHP) or Twig, through tiny adapters.
- **No database, no ORM, no forms:** Kaly stays a small HTTP framework.
- **No auth/CSRF/rate-limit bundled:** provide your own PSR-15 middleware.

## Stability

Internal `lekoala/kaly-di` and `lekoala/kaly-tpl` are `0.x` and may evolve.
Public contracts (`RouterInterface`, `RendererInterface`, `ExceptionHandlerInterface`,
PSR-15 pipeline) are stable within `0.x` — no intentional breaking change.

## Views

Kaly ships no template engine: return a `Kaly\View\View` from a controller and register a
`Kaly\View\RendererInterface` (see [docs/views.md](docs/views.md)). Optional adapters are
provided for Latte, kaly-tpl and Twig.

## Requirements

- PHP 8.3+
- A PSR-7 implementation for your application (Nyholm is recommended); the core only
  depends on the PSR interfaces.

## Installation

```bash
composer require lekoala/kaly
```

## Documentation

See [Documentation](docs/index.html). Run `composer docs` or `composer docsify`.

## Testing

```bash
composer test
```

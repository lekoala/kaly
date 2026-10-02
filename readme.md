# Kaly PHP framework

[![Latest Version](https://img.shields.io/packagist/v/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![Total Downloads](https://img.shields.io/packagist/dt/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![License](https://img.shields.io/packagist/l/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly) [![PHP Version Require](https://img.shields.io/packagist/php-v/lekoala/kaly)](https://packagist.org/packages/lekoala/kaly)

> A small modular PSR HTTP framework with convention-based routing and first-class dependency injection

Kaly is an opinionated but lightweight application framework built on PHP standards:

- **PSR based:** PSR-7 messages, PSR-11 container, PSR-15 middleware.
- **Dependency injection:** powered by [kaly-di](https://github.com/lekoala/kaly-di), autowiring and explicit definitions.
- **Hierarchical routing:** every url belongs to one module, which resolves it with conventions (controllers map to URIs), its own route table or a custom resolver — all declared in its `config.php`.
- **Modular architecture:** each module has its own config, controllers, templates and assets.
- **Middleware support:** plain PSR-15 middleware, applied in bands (incoming, routed, outgoing).
- **Multilingual support:** built-in locale detection.
- **Renderer agnostic:** Latte (recommended), [kaly-tpl](https://github.com/lekoala/kaly-tpl) (lightweight native PHP) or Twig, through tiny adapters.
- **No database, no ORM, no forms:** Kaly stays a small HTTP framework.
- **No auth/CSRF/rate-limit bundled:** provide your own PSR-15 middleware.

## Stability

Kaly is `0.x`: **breaking changes are made deliberately**, in favor of a smaller
and sharper public API rather than piled up behind aliases and deprecation
layers. Read [UPGRADE.md](UPGRADE.md) before upgrading: it lists every removal
and rename, with the migration.

Internal `lekoala/kaly-di` and `lekoala/kaly-tpl` are `0.x` too and may evolve
independently.

`UPGRADE.md` is the record of what changed and why. There is no promise of
backward compatibility within `0.x`.

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
composer require lekoala/kaly nyholm/psr7
```

## Quick start

```php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

Kaly\Core\App::create(dirname(__DIR__))->run();
```

```php
// modules/app/src/Controller/IndexController.php  ->  GET /
namespace App\Controller;

final class IndexController
{
    public function index(): string
    {
        return 'Hello';
    }
}
```

A module is any folder of `modules/` with a `config.php` (it may be empty). Any other
module is routable under its name (`modules/Shop` answers on `/shop/...`).
See [UPGRADE.md](UPGRADE.md) when updating.

## Documentation

- Online: [https://lekoala.github.io/kaly/](https://lekoala.github.io/kaly/)
- Raw Markdown in [`docs/`](docs/) stays readable directly on GitHub
- Local preview: `composer docs` (requires Ruby + Bundler, serves the Jekyll site with live reload)

## Testing

```bash
composer test
```

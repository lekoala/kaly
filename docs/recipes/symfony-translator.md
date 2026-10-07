---
layout: default
title: Symfony Translator recipe
nav_order: 3
---
# Symfony Translator recipe

Kaly's native `Translator` covers message catalogs. For ICU, loaders, or an
existing Symfony catalog, bind the adapter — the framework keeps calling its
small `TranslatorInterface`, Symfony does the heavy lifting:

```php
use Kaly\I18n\Adapter\SymfonyTranslator;
use Kaly\I18n\TranslatorInterface;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyEngine;

$engine = new SymfonyEngine('en');
$engine->addLoader('php', new PhpFileLoader());
$engine->addResource('php', __DIR__ . '/lang/messages.en.php', 'en');

$definitions->set(TranslatorInterface::class, new SymfonyTranslator($engine));
```

Locale selection stays Kaly's (`LocaleResolver`, `RouteLocale`), per-render
binding stays `LocalizedTranslator`: only the translation engine changes. See
[i18n](../i18n.md).

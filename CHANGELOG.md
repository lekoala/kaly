# Changelog

## Unreleased

- Add `Json::pretty()` for indented JSON with unescaped slashes and Unicode.

- Breaking: `Json::decodeMap()` and `decodeMapRelaxed()` now return
  `array<array-key, mixed>` and accept integer-string object keys, which PHP
  converts to integer keys. Objects and lists remain distinct at the JSON boundary.

- Typed translation values: `Kaly\I18n\TranslationKey` (id + domain),
  `Kaly\I18n\Translatable` (`translate(LocalizedTranslator)`) and
  `LocalizedTranslator::resolve()`. A string passed to `resolve()` is always
  a literal and never calls the engine. New
  `Kaly\I18n\TranslatableValidationException` for keyed validation errors,
  translated by the new default `Kaly\Core\LocalizedExceptionHandler` when
  the request has a locale. `ValidationException` and
  `Kaly\Http\ExceptionHandler` are unchanged.
- Breaking: the native translator now matches Symfony on the portable
  subset. A missing key returns the id instead of `{{id}}`, parameters
  replace exact placeholders only, implicit pluralization is removed,
  `Translator::getBaseDomain()`/`setBaseDomain()` are removed, ambiguous
  flat-vs-nested ids throw, and catalogs from several paths merge key by
  key. The translator file cache is removed. `NativeVsSymfonyCompatibilityTest`
  locks the portable contract.

- Breaking: debug mode no longer logs the middleware pipeline automatically on
  each request. Use an `onTerminate()` hook for explicit pipeline logging.
  Middleware tracking in `HttpContext` and its display on the debug error page
  remain available.

## 0.1.0 - 2026-10-02

First tag: a small modular PSR HTTP framework (PSR-7 messages, PSR-11
container, PSR-15 middleware) with convention-based routing, modules and
first-class dependency injection. See UPGRADE.md when updating from an earlier
dev snapshot.

- Restrict the public `/.well-known/` exception to its first path segment;
  hidden files and traversal beneath it remain blocked.
- Reject backslashes in served file and asset paths to prevent hidden-file
  protection bypasses on Windows.

# Changelog

## Unreleased

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

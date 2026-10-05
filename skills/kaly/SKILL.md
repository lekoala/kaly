---
name: kaly
description: Build, modify, debug, and review PHP applications that depend on lekoala/kaly; excludes development of the framework itself.
---

# Kaly application development

Use this skill for applications consuming `lekoala/kaly`. Inside the Kaly
framework repository, follow its contributor documentation and repository
instructions instead.

## Establish the application and version

Before making framework-facing changes:

1. Inspect the application's `composer.json`, `composer.lock`, repository
   instructions and relevant module `config.php`.
2. Treat the installed Kaly source as authoritative; do not assume APIs from
   another release. Kaly is `0.x` and may make deliberate breaking changes.
3. Read the installed package's `UPGRADE.md` when upgrading or investigating
   code that targets a different version.
4. Modify application code rather than files under `vendor/`.

The references in this bundle describe the Kaly version that shipped them.
After copying the skill, check it against the installed package when upgrading.

## Core model

Kaly is a small modular PSR HTTP framework. Prefer its existing conventions
and the application's architecture over introducing extra framework layers.

```text
modules/
  Booking/
    config.php
    src/
      Controller/
      Application/
      Domain/
      Infrastructure/
    templates/
    assets/
```

A directory under `modules/` is discovered only when it has `config.php`.
Each module owns its configuration and URLs; `config.php` is a composition
root. The folders under `src/` are recommended conventions, not requirements:
create only the layers the application needs.

When using those layers, keep HTTP adaptation in controllers, use-case
orchestration in Application, business rules in Domain, and concrete external
adapters in Infrastructure. Keep PSR-7 requests out of business APIs.

Read [application structure](references/application-structure.md) when placing
code, adding persistence or external integrations, or using application paths.

## Request lifecycle and routing

```text
incoming -> routing -> routed -> route middlewares -> dispatcher
         -> outgoing -> commit
```

Within a middleware band, lower priorities run first, then registration order.
Keep custom error pages at the exception-handler boundary; preserve routing's
404/405 semantics and canonical redirects.

Read [HTTP and routing](references/http-and-routing.md) when changing routes,
middleware, canonical URLs, error pages, static files, downloads or assets.

## Shared services and request state

Constructor-inject service dependencies. The container is application-scoped;
shared services must not retain the current user, locale, request or session.
Controllers are created per request and can receive request-context bindings.
Arbitrary services do not receive those bindings automatically.

Read [DI and runtime](references/di-and-runtime.md) when changing bindings,
request state, clocks or long-running workers.

## Views and translations

Use the renderer already selected by the application. Layout composition
belongs to that engine; Kaly's `RendererInterface` stays minimal.
Treat `i18n`, `url`, `asset`, `auth`, `csrf` and `csp` as reserved request-bound
capabilities. Do not mutate a shared translator to select the request locale.

Read [views and i18n](references/views-and-i18n.md) when changing templates,
render data or translations, including kaly-tpl's shared-data rules.

## Authentication and security

Use the application's existing Kaly authentication, permission, session,
CSRF and CSP facilities. Failed authentication must not leave partially
mutated request state.

Read [auth and security](references/auth-and-security.md) when changing
identity, permissions, login, sessions or browser security.

## Before finalizing

Check that the installed version supports the APIs used, code belongs to the
owning module/layer, and request state cannot leak across requests. Verify
middleware ordering, routing semantics and reserved template capabilities
when they are affected. Test the application's behavior; use integration
tests when ordering or composition between components is the actual contract.

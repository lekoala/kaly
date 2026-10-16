# di and runtime

## Dependency injection and request state

Use constructor injection for services.

Kaly's container is application-scoped. A service resolved once may be reused
across multiple sequential or concurrent requests.

Therefore:

- do not store request-specific mutable state in shared services;
- do not store the current user, locale, request, session, route, CSRF token,
  or response in service properties;
- keep request state in `HttpContext`, PSR-7 messages, or local variables;
- shared services and middleware must be safe to re-enter.

Controllers are created per request and may receive `ServerRequestInterface` or
`HttpContext` through their constructor.

Do not assume arbitrary services receive request-context injection.

A stateful resource (entity manager, unit of work) belongs to the operation,
not the request: inject a shared factory and open a fresh resource per
`with()` call, closing it explicitly. There is no request-scope container.

Use the module `config.php` or `App::configure()` to bind services explicitly
when autowiring cannot determine them.

Primitive constructor arguments normally need an explicit definition.

Configure them in one `parameters()` call with named arguments; reserve
`parameter()` for an isolated adjustment. Do not repeat `parameter()` for the
same service:

```php
$di
    ->parameters(
        OpenAICompatibleProvider::class,
        baseUrl: Env::getString('LLM_BASE_URL', 'https://api.example.com/v1'),
        apiKey: Env::getString('LLM_API_KEY'),
        model: Env::getString('LLM_MODEL', 'default-model'),
    )
    ->bind(LlmProvider::class, OpenAICompatibleProvider::class);
```

## Clock and time

Prefer `Psr\Clock\ClockInterface` for application logic that needs "now".

Do not call `new DateTimeImmutable()` or `time()` deep inside business code when
deterministic testing matters.

Use framework/system time only at infrastructure or application boundaries where
appropriate.

## Workers and long-running runtimes

Assume the same booted application may handle many requests.

Code must remain correct under:

- traditional PHP-FPM;
- FrankenPHP;
- RoadRunner;
- Swoole-like worker models;
- Fibers and interleaved request tests.

Never rely on process reset for request-state cleanup.

Any mutable object kept in the application container must be deliberately safe
across requests.

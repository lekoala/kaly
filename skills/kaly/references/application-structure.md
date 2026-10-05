# application structure

## Application boundaries

When the application uses the recommended layered structure, preserve these
dependencies:

```text
Controller       -> Application + Kaly HTTP/View APIs
Application      -> Domain + application ports
Infrastructure   -> Application/Domain ports + external libraries
Domain           -> domain code and framework-neutral foundations
```

### Domain

Domain code must not depend on:

- `Kaly\Http`
- `Kaly\Core`
- `Kaly\View`
- `ServerRequestInterface`
- controllers
- sessions
- templates
- middleware

Small framework-neutral foundations such as clocks or generic utilities may be
used when the application explicitly accepts that dependency.

### Application

Application services and use cases:

- coordinate domain behavior;
- depend on ports where external work is required;
- must not receive PSR-7 requests as business inputs;
- should expose explicit application inputs and outputs.

### Infrastructure

Infrastructure contains concrete adapters for things such as:

- databases and ORMs;
- mail;
- filesystems and object storage;
- external APIs;
- queues;
- vendor SDKs.

Use an application-defined port when the application benefits from one.
Otherwise, depending directly on a library is acceptable.

### Controllers

Controllers are HTTP adapters.

They should:

- accept HTTP/request context when necessary;
- map HTTP input into application input;
- call application use cases;
- return an explicit PSR response, `View`, `JsonResult`, array, or string.

Do not put substantial business logic in controllers.

## Persistence

Kaly intentionally ships no database and no ORM.

Do not search for or invent a "Kaly ORM".

Use the persistence technology selected by the application.

Application/domain code should normally depend on application-defined
persistence ports when decoupling is useful. Concrete Doctrine, PDO, Cycle, or
other implementations belong in Infrastructure.

Transactions belong to the application operation that owns the atomic business
action, not automatically to every HTTP request.

## Application paths

Inject `Kaly\Core\Paths` when infrastructure code needs one of Kaly's
conventional application directories.

Prefer:

```php
final class FileRepository
{
    public function __construct(Paths $paths)
    {
        $this->file = $paths->resources() . '/data.txt';
    }
}
```

over reconstructing paths with `dirname()` or hard-coded absolute locations.

Kaly's conventional directories include:

```text
modules/
public/
resources/
assets/
temp/
```

## External integrations

Third-party integrations belong to the application unless Kaly already exposes
a dedicated integration point.

Typical placement:

- application service: orchestration and use-case behavior;
- application port: when the application needs a stable abstraction;
- infrastructure adapter: vendor SDK, database, mailer, external API;
- middleware: behavior that belongs to the HTTP request/response cycle.

Prefer an existing Kaly extension point when one exists.

Do not modify Kaly or vendor code to integrate an application dependency.

# Request input

> Where the arguments of a controller action come from

## The rule

An action signature must be readable without knowing kaly. Each argument has exactly
one possible origin, visible from its type:

```text
constructor                 services, request, context
action scalar parameters    route segments
one trailing RequestInput   query string and parsed body
return value                the response
```

```php
final class PatientController extends AbstractController
{
    public function __construct(
        ServerRequestInterface $request,
        private PatientService $patients,
    ) {
        parent::__construct($request);
    }

    public function edit(int $id, EditPatientInput $input): View
    {
        // $id is already a valid int coming from the url
        // $input is already typed and validated
        // nothing else can appear here
    }
}
```

Three consequences, and they are the point of the whole contract:

- a service can never appear in an action — the dispatcher supplies every argument;
- an action never parses, casts or trims anything;
- reading `EditPatientInput` tells you the complete input surface of the action.

> **The injector constructs controllers. The dispatcher supplies action inputs.**

## RequestInput

An input object is a plain readonly class implementing the marker interface:

```php
interface RequestInput
{
}
```

```php
final readonly class SearchPatientsInput implements RequestInput
{
    public function __construct(
        public string $query,
        public int $page = 1,
        public ?PatientStatus $status = null,
    ) {}
}
```

The promoted constructor **is** the schema. No attributes, no configuration:

```text
query    required string
page     optional int, default 1
status   optional PatientStatus, default null
```

Rules, deliberately narrow:

- **zero or one** `RequestInput` per action;
- it is always the **last** parameter;
- it never carries route segments.

`action(InputA $a, int $id, InputB $b)` is technically resolvable and is still refused.
The simple rule is worth more than the flexibility.

## Route segments are not input

`/patient/edit/12/` mapping to `int $id` is the main affordance of the
[ClassRouter](class-router.md) and it stays. A route segment is part of the route, so a
value that does not fit its type does not match the route:

```text
/patient/edit/foo/   ->  404, no route
```

Route values are never merged into the input object. There is therefore no precedence
rule to remember between the url and the body.

## Merging query and body

The remaining two sources are merged, without a hidden winner:

```text
key only in the query   -> the query value
key only in the body    -> the body value
key in both             -> accepted if equivalent after coercion
                           400 if they contradict each other
```

Accepting equivalent duplicates matters in practice: a client doing
`PUT /patients/12/` with the whole object in the body legitimately repeats values.
Contradicting values are a client bug and must be reported as one.

This is explicitly **not** a silent body-overrides-query merge: contradicting
values are a client bug and must be reported as one. The low-level
`RequestUtils::getRequestParam()` accessor stays as an infrastructure helper
and is not the application path.

## Parsing is not validation

Mapping answers: *can this data be represented by this type?*

```text
"42"       -> int 42                  yes
"foo"      -> int                     no
"active"   -> PatientStatus::Active   yes
"nonsense" -> PatientStatus           no
```

Validation answers: *is this typed value acceptable?* It belongs to the input object
itself — its constructor, or an optional contract:

```php
interface ValidatableInput
{
    public function validate(): void;
}
```

```php
final readonly class PaginationInput implements RequestInput
{
    public function __construct(
        public int $page = 1,
        public int $limit = 20,
    ) {
        if ($page < 1) {
            throw new ValidationException('page must be >= 1');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ValidationException('limit must be between 1 and 100');
        }
    }
}
```

Value objects encode both at once and are the recommended way to share an invariant:

```php
final readonly class Email
{
    public function __construct(public string $value)
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email");
        }
    }
}
```

## Status codes

The three failures are distinct and must not collapse into one:

| Case | Example | Status |
| --- | --- | --- |
| a route segment does not fit its type | `/patient/edit/foo/` | **404** |
| the typed input cannot be built | `/patient/edit/12/?page=foo` | **400** |
| the input is typed but refused | `?page=-4` | **422** |

A 404 means *there is no such route*. A 400 means *the route exists, the data is
unusable*. A 422 means *the data is well formed and still unacceptable*.

`Kaly\Http\InputException` carries the 400, `Kaly\Http\ValidationException` the 422.
Both are `HttpExceptionInterface`, so the kernel turns them into responses on its own.

## Supported conversions

`Kaly\Http\InputMapper` is bound by default and covers, deliberately, only this:

| Declared type | Accepted |
| --- | --- |
| `string` | any scalar |
| `int` | an integer, or a string that is exactly one (`"1.5"` is not) |
| `float` | a number, or a numeric string |
| `bool` | `1/0`, `true/false`, `on/off`, `yes/no` |
| `array` | a real array — `?tag[]=a&tag[]=b`, never a separator convention |
| backed enum | a value matching one of its cases |

Plus nullability and default values. A missing property falls back to its default, then
to `null` if the type allows it, otherwise it is a 400.

An **empty value counts as missing** for every type but `string`: a form posts all of
its empty fields, so `?page=` means "no page", not "page is the empty string".

`DateTimeImmutable`, nested inputs and value objects are left out until the convention
for each is decided explicitly. A property the mapper cannot build throws a
`LogicException`: it would fail for every single client, so it is a programming error,
not a bad request.

Bind your own `Kaly\Http\InputMapperInterface` to replace the whole thing.

## Breaking change

An action parameter typed as an object that is not a `RequestInput` is refused, where it
used to be filled from the container when it was optional:

```php
// was silently injected, now an error at routing time
public function search(string $q = '', ?LoggerInterface $logger = null)
```

Move it to the constructor. This is the whole point of the contract: the injector
constructs controllers, it never supplies action arguments.

The parsed body is no longer appended automatically as the last action argument on
POST/PUT/PATCH, which used to make `save(array $data)` a second, untyped door:

```php
// before
public function savePost(array $data)

// now
public function savePost(SaveInput $input)
```

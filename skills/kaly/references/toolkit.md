# toolkit

Kaly ships small building blocks. Before writing a helper, check whether the
installed version already has it, and read its page in the package's `docs/`.

## Secrets and signatures

- `Kaly\Crypto\Secret` is the application secret, resolved from `APP_SECRET`.
  Never read `APP_SECRET` directly; the raw bytes are not exposed by design.
- `Kaly\Crypto\Hmac` signs what the application issues and verifies itself:
  codes, links, tokens, callback urls. One versioned purpose per use
  (`auth-verification:v1`), built once in a factory. Bind every field in the
  message unambiguously (`json_encode([$id, $email, $code])`), never by
  concatenation.
- Never hash a short code with a plain digest, never compare signatures with
  `===` or SQL equality: load the record, then call `verify()`.
- Expiry, attempt limits and single use stay application rules.
- A third-party signature (webhook from GitHub, Stripe, a support desk) is not
  an `Hmac` use: it follows the provider's secret and encoding. Use its SDK, or
  `hash_hmac()` over the raw body with `hash_equals()`, the secret in its own
  environment variable and never logged.
- Passwords go through `Kaly\Auth\PasswordHasher`, encryption through
  libsodium or a dedicated library. Neither is `Hmac`.

## Debugging and profiling

- `$app->profiling(enabled: ..., serverTiming: ...)` measures routing,
  controller, serialization, view and commit without instrumenting code.
  Export `Server-Timing` in development only; in production, read
  `$ctx->profile()?->metrics()` in an `onTerminate()` hook for internal logs.
- Record an application operation with
  `$ctx->profile()?->record('db', $elapsedNanoseconds)`, measured with
  `hrtime(true)`, not the clock.
- Correlate logs with `$ctx->requestId()`; `RequestIdHeader` exposes it.
- `d()` / `dd()` are global helpers loaded explicitly from
  `src/Debug/functions.php`, inert without `APP_DEBUG`. Never leave a dump in
  committed code.
- See the package's `docs/debugging.md` and the Server-Timing recipe.

## Values, input and validation

- `Kaly\Util\Env` reads deployment values with typed accessors
  (`getString`, `getInt`, `getBool`, `getList`...). `Env::parse()` validates
  a `.env.example` in a test without touching the environment.
- `Kaly\Util\Json` encodes and decodes with exceptions and typed shapes
  (`decodeMap`, `decodeList`); do not call `json_decode()` and check errors by
  hand.
- `Kaly\Util\Assert` narrows mixed values (`string`, `isMap`, `instanceOf`...),
  `Kaly\Util\Cast` converts input leniently (`intOrNull`...).
- `Kaly\Util\Dates` parses dates, times and instants strictly; follow the
  time conventions of the application structure reference.
- `Kaly\Util\Base64Url`, `Str`, `Arr` and `Fs` cover encoding, slugs, lists
  and paths.
- `Kaly\Validation\Validator` collects violations into a `ValidationResult`;
  parsing input is not validating it.

## HTTP responses

- Client failures are `Kaly\Http\Exception\*`: `NotFoundException`,
  `ForbiddenException`, `UnauthorizedException`, `TooManyRequestsException`
  (with `Retry-After`), `RedirectException`. Throw them rather than building
  error responses by hand.
- Downloads go through `Kaly\Http\FileResponseFactory`.
- Rate limiting is not bundled: follow the rate limiting recipe.

## Tasks and logs

- Defer work through `Kaly\Task\TaskDispatcher`; `DirectTaskDispatcher` runs
  it in process. A queue is an application choice.
- `Kaly\Log\FileLogger` is a minimal PSR-3 logger; the application may bind
  any other.

## Tests

- `Kaly\Test\TestClient::for($app)` drives full cycles, with cookies and
  redirects.
- `Kaly\Clock\FrozenClock`, `Kaly\Test\MemorySessionProvider` and
  `Kaly\Test\RecordingTaskDispatcher` replace time, sessions and tasks.

# AGENTS.md

Kaly is pre-1.0 software, converging toward 1.0. See
[docs/versioning.md](docs/versioning.md) for the stability policy.

Before 1.0, prioritize stabilizing the documented application-facing contracts.
Breaking changes remain permitted when they correct a demonstrated architectural or
semantic problem, not merely to improve naming, symmetry, or internal organization.

When making a breaking change:

- state the demonstrated reason;
- update all first-party usages, tests, documentation, and examples in the same change;
- add an entry to `UPGRADE.md` and migrate the witness application (once it exists);
- prefer one clean API over old/new APIs living side by side: no aliases, shims, or
  deprecation layers before 1.0;
- mention the breaking change clearly in the release notes.

Adding a required method to an interface that applications implement is a breaking
change: evolve a capability through a separate optional interface instead.

## Design

- Follow the [time dependency conventions](docs/application-structure.md#time-dependencies)
  for application behavior, parsing, persistence and presentation.
- Validate declarations early when validation is cheap and already belongs to
  a compilation step — eg route table normalization. Otherwise, let normal
  execution expose invalid application code; the framework is not a static
  analyzer of the application.

## Architecture

- A middleware independent of the Kaly runtime lives with its domain under
  `*\Middleware` (`Http\Middleware`, `Asset\Middleware`, `Auth\Middleware`).
  A middleware that requires `HttpContext` or other Core lifecycle state
  belongs to `Kaly\Core\Middleware`. Group by runtime dependence, never by
  pipeline role.

## Style

- Declare class constants first, then properties, then methods, starting with
  the constructor. Keep constructor-promoted properties in the constructor.
- Exception messages are a single sentence, with no trailing punctuation. This
  applies to native exceptions too (`InvalidArgumentException`, `LogicException`,
  `RuntimeException`), not only to `Kaly\Ex`.
- Client-facing failures extend `Kaly\Http\Exception\HttpException` and carry their own
  status, headers and body. A non-HTTP failure is a plain `Kaly\Ex` and
  carries no status.

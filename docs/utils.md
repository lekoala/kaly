---
layout: default
title: Utilities
nav_order: 25
---
# Utilities

Small framework-independent foundations shared by the domain, the
application and Kaly itself. The PHP classes are the reference — signatures,
contracts and edge cases live in their docblocks — this page only maps the
territory. See [Building a Kaly application](application-structure.md) for the
boundary rule: `Domain` and `Application` may use `Kaly\Util` and `Kaly\Clock`
because they carry no HTTP or runtime state.

## Time

Two questions, two answers:

- **What time is it?** Inject `Psr\Clock\ClockInterface`, bound by default to
  `Kaly\Clock\SystemClock` (PHP default timezone, or an explicit one), and
  replace it with `Kaly\Clock\FrozenClock` in tests. See the
  [time dependency conventions](application-structure.md#time-dependencies).
- **Is this string a valid date?** Use `Kaly\Util\Dates`, described below.

## Dates

`Kaly\Util\Dates` strictly parses the three representations the web keeps
handing over: `Y-m-d` dates, `H:i` or `H:i:s` times and RFC 3339 instants with an
explicit offset. It returns plain `DateTimeImmutable` values.

`Dates::compareDate($a, $b)` compares the calendar dates of two
`DateTimeInterface` objects and returns `-1`, `0` or `1`. It reads each date
in its own timezone, ignores the time and modifies neither object. It does
not convert timezones or compare instants: the same instant can have different
calendar dates in different timezones.

Conventions to know:

- Throwing factories (`date()`, `instant()`, `at()`) for already-validated
  boundaries, nullable `try*` twins for user input, `is*` predicates for
  guards — the same strict-by-default shape as the JSON boundary.
- For `date()` and `at()`, a missing timezone means the PHP default timezone
  (as with `SystemClock`); `''` falls back to `UTC`.
- `instant()` and `tryInstant()` require and preserve the input offset;
  they never use the PHP default timezone. Use native `setTimezone()` to
  convert the result to another timezone.
- `isDate()` is a pure calendar check while `date()` guarantees a real local
  midnight: a midnight skipped by a DST transition is rejected, and an
  unknown timezone string always throws, even when the value is invalid too.
- The instant subset is narrow on purpose: a `T` separator in either case,
  seconds `00`–`59`, optionally 1 to 6 fractional digits, offset `Z` (either
  case) or `±HH:MM`.
  Leap seconds and the unknown `-00:00` offset are rejected.

What it is not: durations, arithmetic, calendars, humanization and rich
`LocalDate` modeling stay out. Reach for `brick/date-time`, `bakame/tokei`
or Carbon instead — all three are listed in `suggest`.

### Parsing vs. application conventions

`Dates` validates common date and time representations. Source-specific
conventions, such as database zero dates, import formats and missing-value
sentinels, belong to their respective adapters.

Applications define timestamp storage formats and precision at the persistence
boundary. Use `Dates::instant()` to validate supported RFC 3339 values, and
native `DateTimeImmutable` operations for timezone conversion and formatting.
Checking an exact canonical storage representation belongs in the application's
persistence codec.

## Values in, values out

- `Kaly\Util\Json` owns the JSON boundary: strict encode/decode with shape
  assertions (`decodeMap()` / `decodeList()`), plus a small `*Relaxed`
  family for hand-written config.
- `Kaly\Util\Types` narrows one decoded field at a time (`stringOrNull()`,
  `listOrEmpty()`, …). Anything carrying domain semantics — statuses,
  domain dates, ids — stays in the domain.

## Environment, strings, files

- `Kaly\Util\Env` reads deployment values (`.env` fills the gaps, process
  environment wins) for `module/config.php`. Details in [App](app.md).
- `Kaly\Util\Str` (multibyte case, slugs, encoding), `Kaly\Util\Fs`
  (dot-segment guard, recursive helpers), `Kaly\Util\Arr`,
  `Kaly\Util\Cast` and `Kaly\Util\Base64Url` round out the toolbox.

### Filesystem paths

Use `Fs::join(string ...$segments)` to compose filesystem paths:

```php
$databaseFile = Fs::join($paths->resources(), 'database.sqlite');
Fs::ensureDir(Fs::join($paths->publicDir(), 'uploads'), 0o775);
```

`join()` ignores empty strings, preserves `"0"` and filesystem roots (Unix,
Windows drives and UNC shares), and uses `DIRECTORY_SEPARATOR` at junctions.
Interior separators are unchanged and trailing separators are removed.
Only the first non-empty segment may be rooted or carry a Windows drive prefix;
later rooted or drive-prefixed segments throw `Kaly\Ex`.
It does not require an existing path, call `realpath()` or resolve `.` / `..`.
It is not a path traversal security boundary.

`Fs::dir()` removes trailing separators while preserving roots.

`Fs::relativePath($base, $path)` strips the base directory and its separator
at a directory boundary: `/app/modules/Foo/src` relative to `/app` becomes
`modules/Foo/src`, while `/application/src` remains unchanged. This operation
is lexical, accepts both separator styles and compares case-sensitively.

`Fs::removeDir()` removes a directory recursively, removing symlinks and Windows
junctions themselves without following their targets.

`Fs::getFile()` returns an empty string for unreadable files;
`Fs::contentType()` falls back to `application/octet-stream` when MIME detection
fails, including with Kaly's error handler enabled.

`Fs::ensureDir($directory, $mode = 0o755)` creates directories recursively and
tolerates another process creating the directory concurrently. Creation failures
throw `Kaly\Ex`. The mode is subject to umask, is ignored on Windows, and does
not change permissions on existing directories.

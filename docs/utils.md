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

- **What time is it?** Depend on `Psr\Clock\ClockInterface`, inject
  `Kaly\Clock\SystemClock` (PHP default timezone, or an explicit one) and
  freeze it with `Kaly\Clock\FrozenClock` in tests.
- **Is this string a valid date?** Use `Kaly\Util\Dates`, described below.

## Dates

`Kaly\Util\Dates` strictly parses the three representations the web keeps
handing over: `Y-m-d` dates, `H:i` or `H:i:s` times and RFC 3339 instants with an
explicit offset. It returns plain `DateTimeImmutable` values.

Conventions to know:

- Throwing factories (`date()`, `instant()`, `at()`) for already-validated
  boundaries, nullable `try*` twins for user input, `is*` predicates for
  guards — the same strict-by-default shape as the JSON boundary.
- A missing timezone means the PHP default timezone (as with `SystemClock`);
  `''` falls back to `UTC`. `instant()` never consults it: the offset is
  mandatory in the string.
- `isDate()` is a pure calendar check while `date()` guarantees a real local
  midnight: a midnight skipped by a DST transition is rejected, and an
  unknown timezone string always throws, even when the value is invalid too.
- The instant subset is narrow on purpose: optional `T`/`Z` in either case,
  seconds `00`–`59`, 1 to 6 fractional digits, offset `Z` or `±HH:MM`.
  Leap seconds and the unknown `-00:00` offset are rejected.

What it is not: durations, arithmetic, calendars, humanization and rich
`LocalDate` modeling stay out. Reach for `brick/date-time`, `bakame/tokei`
or Carbon instead — all three are listed in `suggest`.

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

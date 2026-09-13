# Security Policy

## Trust boundary: config and cache are code

Kaly loads PHP files at runtime:

- `src/Core/Module.php`: module `config.php` via `require`
- `src/Text/Translator.php`: compiled catalog cache via `file_put_contents` + `require`
- `src/Util/Fs.php`: filesystem helpers (`mkdir`, `unlink`, `rmdir`, `glob`, `opendir`)

If `temp/`, `cacheDir`, or `modulesDir` is writable by an untrusted user, it can lead to
remote code execution or cache pollution. This is by design (config = code).

Recommendations:

- Never expose `temp/`, cache, or modules directories for writing by the web server user
  beyond what the deploy user owns.
- Never share the file cache between mutually untrusted apps.
- Set restrictive permissions on deploy (`noexec`/`nosuid` where possible, read-only
  modules in production).
- Keep `APP_DEBUG=false` in production: traces are only rendered when debug is on
  (`src/Core/ErrorHandler.php` escapes output with `htmlspecialchars`).

## Static files

`src/Middleware/Builtin/FileServer.php` only serves `GET`/`HEAD`, rejects path traversal
via `Fs::isInside`, requires `is_file`, and never serves executable extensions
(`php`, `phtml`, `phar`, `php3-8`, `pht`, `inc`, `cgi`, `pl`).

Large files are streamed in chunks with `Content-Length` and `HEAD` support; no
`Range` / `X-Sendfile` handling is provided — put a CDN or web server in front for
heavy static traffic.

## Not provided

Kaly ships no auth, CSRF, or rate-limit middleware. Provide your own PSR-15
implementations in the `incoming` / `routed` bands and scope them with `HttpContext`
conditions.

## Reporting

Report suspected vulnerabilities via a private channel to the maintainer instead of
opening a public issue. Include reproduction steps, affected version, and impact.

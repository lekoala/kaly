# Security Policy

## Trust boundary: config and cache are code

Kaly loads PHP files at runtime:

- `src/Core/Module.php`: module `config.php` via `require`
- `src/I18n/Translator.php`: compiled catalog cache via `file_put_contents` + `require`
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

`src/Http/Middleware/FileServer.php` only serves `GET`/`HEAD`, rejects path traversal
via `Fs::isInside`, requires `is_file`, and never serves executable extensions
(`php`, `phtml`, `phar`, `php3-8`, `pht`, `inc`, `cgi`, `pl`).

Dotfiles and dot-prefixed directories are refused. Only the leading
`/.well-known/` directory is allowed; hidden segments beneath it are still
blocked. Backslashes in served paths are rejected on every platform.

Large files are streamed in chunks with `Content-Length` and `HEAD` support; no
`Range` / `X-Sendfile` handling is provided — put a CDN or web server in front for
heavy static traffic.

Private downloads use `src/Http/FileResponseFactory.php` directly, after an
application access check: it only accepts already-authorized storage paths
(never raw request input), requires a regular file, and encodes
`Content-Disposition` with no injectable bytes. The forbidden-extensions
policy belongs to the public `FileServer`, not to the primitive.

## Auth, CSRF and CSP

Kaly provides the browser-facing primitives: request identity with a closed
permission set (`Kaly\Auth\Authentication`, `PermissionSet`), the session
login/logout lifecycle (`Kaly\Auth\SessionAuthentication`), HTTP `Authorization`
parsing, CSRF (`Kaly\Http\Csrf\Csrf`, `Kaly\Core\Middleware\CsrfMiddleware`),
CSP nonces and the method override middleware. See
[docs/auth.md](docs/auth.md) and [docs/security.md](docs/security.md).

Access policy is never bundled: Kaly establishes an identity and ships the
pipeline slot, but whether an anonymous request is redirected or answered 401
stays an application guard. Password hashing, remember-me, JWT, OAuth and OIDC
are out of scope and converge on the same `Authentication`.

## Not provided

Kaly ships no rate-limit middleware. Provide your own PSR-15 implementation in the
`incoming` / `routed` bands and scope it with `HttpContext` conditions.

## Reporting

Report suspected vulnerabilities via a private channel to the maintainer instead of
opening a public issue. Include reproduction steps, affected version, and impact.

- Preferred: GitHub Private Vulnerability Reporting on the repository
- Fallback: thomas@lekoala.be

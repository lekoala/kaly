---
layout: default
title: Second factors
nav_order: 13
---
# Second factors

Password plus a second factor is a login with an intermediate state: the
password is verified, but the user is not authenticated yet. Kaly defines
that lifecycle and leaves the cryptography to specialized packages. There
is no TOTP or WebAuthn engine in the framework, and none is planned.

The boundary is deliberate:

> **The application owns the pending-login lifecycle, using Kaly's session,
> authentication, CSRF and clock primitives. External packages own the
> factor cryptography.**

That means the application stores a pending challenge in the session,
verifies it through a maintained library, and only then calls
`SessionAuthentication::login()` — which keeps one strong meaning:
this identity has satisfied every authentication requirement and may
become the principal of the request. See [Auth](auth.md).

## Recommended packages

TOTP/HOTP (RFC 4226/6238) via `spomky-labs/otphp`, which is actively
maintained and builds on `psr/clock` — a contract Kaly already depends
on:

```bash
composer require spomky-labs/otphp
```

WebAuthn/FIDO2 (including passkeys) via `web-auth/webauthn-lib`, which
absorbs the RP ID, origin, challenge, attestation, counter and credential
discovery subtleties no application should reimplement. WebAuthn can
later graduate to passwordless, beyond the password-plus-factor model,
so think of it as strong authentication rather than strictly "2FA":

```bash
composer require web-auth/webauthn-lib
```

`scheb/2fa-bundle` is worth reading as a use-case checklist — pending
authentication, multiple factors, backup codes, trusted devices, routes
allowed during challenge, conditional 2FA — but it is built around the
Symfony Security architecture, not around Kaly, so it is a reference,
not a dependency.

There is deliberately no `Kaly\Auth\Totp`, no `TwoFactorInterface` and
no `kaly-2fa` package: a wrapper would only hide the specialized
library. A shared `Kaly\Auth\PendingAuthentication` primitive may earn
its place once several real applications repeat the same shape; until
then the recipe below is application code.

## The lifecycle

```text
POST /login
    ↓
password correct
    ↓
2FA required ?
    ├─ no → SessionAuthentication::login()
    │
    └─ yes
        ↓
        start from an anonymous session (see below)
        store pending challenge:
            identifier
            expires_at
        redirect /login/2fa

POST /login/2fa
    ↓
retrieve pending challenge
    ↓
missing or expired → discard it, restart login
    ↓
verify factor
    ↓
failure → halt, never fall through to login()
    ↓
consume pending challenge
    ↓
SessionAuthentication::login()
    ↓
authenticated
```

The essential choice:

> **After the password, the user is not `Authentication::isAuthenticated()`.**

Never establish a partially-authenticated principal with fewer
permissions: that notion would contaminate every guard with
authentication levels. A pending login stays an **anonymous session
carrying a challenge**.

## Lifecycle illustration

The snippets below show the shape of the flow. They are a
lifecycle illustration, not a complete and safe integration: every
hardening rule in the following sections still applies on top of it.
`$session` is `$ctx->session()` and `$clock` is the injected
`Psr\Clock\ClockInterface`. `redirect()`, `restartLogin()` and
`factorError()` are application response helpers, not Kaly functions.
The error helper returns a validation error response; the factor service
records the failed attempt against the account and challenge.

```php
// POST /login, password already verified, 2FA required.
if ($ctx->auth()->isAuthenticated() || $sessionAuth->identifier($session) !== null) {
    return restartLogin('Log out before starting a new login');
}

$session->remove('_2fa');
$sessionAuth->logout($session, $ctx->auth()); // Clears identity and CSRF, rotates id.
$session->set('_2fa', [
    'identifier' => (string) $user->id,
    'expires_at' => $clock->now()->getTimestamp() + 300,
]);

return redirect('/login/2fa');
```

```php
// POST /login/2fa.
$pending = $session->get('_2fa');

if (!is_array($pending)
    || !is_string($pending['identifier'] ?? null)
    || $pending['identifier'] === ''
    || !is_int($pending['expires_at'] ?? null)
    || $clock->now()->getTimestamp() >= $pending['expires_at']
) {
    $session->remove('_2fa');

    return restartLogin('The challenge has expired');
}

$identity = $users->authenticationIdentity($pending['identifier']);
if ($identity === null) {
    $session->remove('_2fa');

    return restartLogin('The account is unavailable');
}

if (!$factors->verifyAndConsume($identity->principal, $code, $clock)) {
    return factorError('Invalid authentication code');
}

$session->remove('_2fa');
$sessionAuth->login($session, $ctx->auth(), $pending['identifier'], $identity->principal, $identity->permissions);
```

`$users->authenticationIdentity()` is the application repository method
used in [Auth](auth.md): it reloads the principal and current permissions,
and must refuse accounts that can no longer log in. Never take the identity
or factor secret from submitted form fields. `$factors->verifyAndConsume()`
is an application service, not an OTPHP or Kaly API: it enforces throttling,
loads the enrolled factor, verifies the code through the library, and
atomically records its use as described below. A missing or changed factor
must restart login rather than bypass verification.

The pending payload is minimal on purpose — `identifier + expires_at`.
Anything richer (requested method, satisfied factors, challenge id,
return URL, per-challenge attempts) is application state that can grow
toward WebAuthn/MFA later, and does not belong in a framework shape yet.

## Start from an anonymous session

`SessionInterface::regenerateId()` rotates the id but keeps the data.
Calling it alone does not anonymize anything: a previous `_auth`
reference survives the rotation, and the current request already
carries its restored principal in `Authentication`.

So before writing `_2fa`, do one of two things explicitly:

1. Refuse to start a challenge while authenticated — the snippet above
   refuses both a current principal and an existing session identity.
   The user logs out first, then logs in again.
2. Or explicitly end the previous authentication first through
   `SessionAuthentication::logout()` — then create the
   challenge. Ending then challenging must be a conscious sequence, never
   a leftover of `regenerateId()`.

The same staleness applies between challenges: starting a new login
must discard any previous `_2fa` at the start of `POST /login`, including
when credentials fail or the new login does not require a second factor.
The illustration repeats that removal before creating the new challenge.

## Failure must halt

A failed verification must stop the request: `throw` a validation
failure or `return` an error response from the challenge action. What
it must never do is record the error and continue into the code below,
where `SessionAuthentication::login()` waits a few lines further. The
second snippet returns on a failed `verifyAndConsume()` for exactly this
reason — a comment where the error handling goes is an invitation to
fall through into `login()`.

## Cleaning up is applicative

`SessionAuthentication::logout()` removes the `_auth` reference and the
CSRF secret but preserves unrelated session data such as carts and
wizards — so it will never remove `_2fa` for you. The recipe must clean
the pending challenge explicitly in every terminal path:

- success: remove `_2fa` before calling `login()`;
- expiry: remove `_2fa` when the challenge is missing or expired, then
  restart the login from the password step;
- abandon: the cancel action and the logout action both remove `_2fa`
  alongside their other work;
- replacement: a new `POST /login` removes any previous `_2fa` before
  writing the fresh challenge.

```php
// Cancel action, and likewise inside the logout action.
$session->remove('_2fa');

$sessionAuth->logout($session, $ctx->auth());
```

An expired or consumed challenge restarts at the password, never at the
challenge: re-entering `/login/2fa` without a live `_2fa` redirects to
`/login`.

## Challenge routes stay anonymous, with CSRF

The challenge pages must be reachable without authentication — the user
is anonymous by construction until the final `login()`. Mount them on
the public scope, next to the login page, never behind the authenticated
guard. Anonymous does not mean unprotected: mount `CsrfMiddleware` on
that scope exactly like the login form (login-CSRF without requiring
authentication), so challenge submissions carry a token. See
[Security](security.md).

Any `return_url` carried through the challenge must be validated as an
allowed local destination before redirecting — a path within the
application, excluding scheme-relative URLs such as `//example.com`.
Prefer an allowlist of application routes. An unvalidated return URL
turns the login into an open redirect.

Send `Cache-Control: no-store` on login, challenge and enrollment responses.
The `isAuthenticated()` condition shown in [Security](security.md) does not
cover a pending login, because that session is anonymous.

## Anti-replay is server-side state

Removing `_2fa` prevents reuse on subsequent requests, but session storage
alone does not guarantee single consumption by concurrent requests. Nor does it
stop the TOTP code itself from being replayed: within its time window
the same code still verifies, including from another session. Track
per-authenticator replay state — the last accepted time-step (and, for
HOTP, the counter) — and refuse a step at or below the last accepted step even when the code
is cryptographically valid. Update this state atomically with successful
verification, using the step that actually matched within the allowed window,
not necessarily the server's current step. OTPHP's `verify()` returns a
boolean; it does not persist this replay state for the application.

Recovery codes also require atomic consumption: each code is
single-use and its consumption must be atomic — check-and-invalidate in
one indivisible operation, so two concurrent requests cannot redeem the
same code twice.

Neither guarantee can rely solely on the session: `SessionInterface` promises
storage, not atomicity between concurrent requests. Keep replay and
recovery state in the account store, under a transaction or an atomic
compare-and-set, and treat a lost race as a verification failure.

## Rate-limit the account, not just the challenge

A per-challenge attempt counter stored next to `_2fa` is trivially
reset: restart the login, get a fresh challenge, get a fresh budget.
Limit attempts at two levels:

- challenge-level attempts, to bound guessing against one challenge;
- account-level throttling, independent of any challenge — per user id,
  with progressive delays or temporary lockout — so abandoning and
  restarting the login never resets the budget.

This follows the [OWASP multifactor](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html)
and [authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
guidance: failed factor attempts and recovery attempts are limited and
logged per account, not per session artifact.

## Enrollment and factor changes

Never activate a factor before its first proof: generate the secret,
show the QR code, then require one valid code against that secret
before marking the factor enabled. An enrolled-but-never-verified
secret must not count as a second factor at login.

Changing or disabling a factor requires recent full authentication through
the application's accepted factors. For password-plus-TOTP, require the
password and an existing enrolled factor, either just verified in this flow
or recorded server-side as verified within a short window (minutes, not
hours). An authenticated session alone is not proof of recent verification.
The password alone must not authorize factor replacement. A lost factor
needs a separate recovery policy, using a recovery code, another enrolled
factor, or a rigorously verified account-recovery process; it must not
silently downgrade to password-only authentication. See the
[OWASP factor-change guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html#changing-mfa-factors).

## Storage and operational rules

- Rotate the session id after the first factor and again at the final
  `login()` (fixation protection on both transitions).
- Keep the pending challenge short-lived via `ClockInterface` (minutes),
  and consume it on success.
- Store TOTP secrets **encrypted at rest** — they must be recoverable
  for verification, so hashing is not an option; protect the encryption
  key outside the database.
- Store recovery codes **hashed and single-use**, each invalidated at
  first use.
- Keep the tolerated time window small and refuse replayed steps as
  described above. In [OTPHP 11.x](https://github.com/Spomky-Labs/otphp/blob/11.4.0/src/TOTP.php),
  `verify()` defaults to the current step; its optional `leeway` is in
  seconds and must be smaller than the configured period, not a count of steps.
  Pass the same application clock to the TOTP instance and the pending flow.
- Never log the TOTP secret, a submitted code, or sensitive WebAuthn
  challenge data.
- Do not treat email or SMS codes as equivalent to TOTP or WebAuthn in
  security level; document which factors the application accepts and why.

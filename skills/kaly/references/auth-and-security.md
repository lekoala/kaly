# auth and security

## Authentication, session, CSRF, and CSP

Prefer Kaly's existing facilities rather than implementing these independently
inside controllers.

Authentication and authorization are separate concerns:

- authentication establishes identity;
- permissions and policies decide whether an action is allowed.

A failed authentication or login operation must not leave partially mutated
request state.

Session, authentication state, CSRF state, and CSP nonce are request-scoped.
They must not leak into subsequent or concurrent requests.

<?php

declare(strict_types=1);

namespace Kaly\Http\Csrf;

use Kaly\Http\Session\SessionInterface;

/**
 * The read-only face of CSRF protection for templates.
 *
 * Exposes the raw token value, escaped by the renderer: Kaly never produces
 * HTML here. The session may be given directly or as a factory that is only
 * called by token(), so rendering a view without using csrf never creates
 * the session: building the wrapper touches no storage.
 */
final readonly class CsrfView
{
    /**
     * @param SessionInterface|\Closure $session The session, or a factory returning it
     */
    public function __construct(
        private Csrf $csrf,
        private SessionInterface|\Closure $session,
    ) {}

    public function token(): string
    {
        return $this->csrf->token($this->resolveSession());
    }

    public function fieldName(): string
    {
        return Csrf::FIELD;
    }

    public function headerName(): string
    {
        return Csrf::HEADER;
    }

    private function resolveSession(): SessionInterface
    {
        $session = $this->session;

        if ($session instanceof \Closure) {
            $session = $session();
            assert($session instanceof SessionInterface);
        }

        return $session;
    }
}

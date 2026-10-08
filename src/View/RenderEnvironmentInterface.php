<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * The reserved capabilities of a single render, as the renderer sees them.
 *
 * The renderer only needs to reject page data that collides with a reserved
 * name and to inject the capabilities into its engine; it never needs to know
 * the concrete types behind them. The typed environment that composes the
 * domains (translation, router, assets, auth, CSRF, CSP) lives in Core, which
 * is allowed to depend on them; View stays a peer domain.
 */
interface RenderEnvironmentInterface
{
    /**
     * Reject page data that collides with a reserved capability name.
     *
     * @param array<string,mixed> $data
     */
    public function assertCompatible(array $data): void;

    /**
     * The capabilities as template variables, keyed by their reserved names.
     *
     * @return array<string,mixed>
     */
    public function variables(): array;
}

<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The default session factory: native PHP sessions, one instance per request.
 *
 * Sequential execution only: PHP's native session state is global to the
 * process, so concurrent runtimes must bind a factory returning a
 * request-scoped SessionInterface implementation instead.
 */
final readonly class NativePhpSessionFactory implements SessionFactoryInterface
{
    /**
     * @param array<string,mixed> $options Passed to the native session
     */
    public function __construct(
        private array $options = [],
        private ?CookiePolicy $policy = null,
    ) {}

    public function create(ServerRequestInterface $request): SessionInterface
    {
        return new NativePhpSession($this->options, $request, $this->policy);
    }
}

<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request-scoped in-memory sessions, one instance per request.
 *
 * Concurrency-safe by construction. For tests and isolated cycles: nothing
 * persists between two requests. This is not a production backend.
 */
final class ArraySessionProvider implements SessionProviderInterface
{
    private CookiePolicy $policy;
    /**
     * @var array<string,mixed>
     */
    private array $options;

    /**
     * @param array<string,mixed> $options Passed to ArraySession ('name' plus
     *  cookie params). Explicit options win over the policy baseline.
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->options = $options;
        $this->policy = $policy ?? CookiePolicy::baseline();
    }

    public function create(ServerRequestInterface $request): SessionInterface
    {
        $options = $this->options;
        if (!array_key_exists('secure', $options)) {
            $options['secure'] = $request->getUri()->getScheme() === 'https';
        }
        if (!array_key_exists('domain', $options)) {
            $options['domain'] = $request->getUri()->getHost();
        }
        $session = new ArraySession($options, $this->policy);

        $cookies = $request->getCookieParams();
        $param = $cookies[$session->getName()] ?? null;
        if ($param !== null && !is_string($param)) {
            throw new InvalidArgumentException('Session cookie value must be a string');
        }
        if ($param !== null) {
            $session->setId($param);
        }

        return $session;
    }

    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session instanceof ArraySession) {
            return $session->commitToResponse($request, $response);
        }
        if ($session instanceof NativePhpSession) {
            return $session->commitToResponse($request, $response);
        }
        return $response;
    }
}

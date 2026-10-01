<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use InvalidArgumentException;
use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\Cookie\SetCookieHeader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The transport rules every cookie-carried session shares.
 *
 * Read the incoming id, resolve the cookie options for the request, release
 * the storage and apply the Set-Cookie header to the final response. Providers
 * delegate here instead of knowing concrete session classes.
 */
final class SessionCookie
{
    /**
     * The session id the request carries in its cookies, or null.
     */
    public static function idFromRequest(ServerRequestInterface $request, string $name): ?string
    {
        $param = $request->getCookieParams()[$name] ?? null;
        if ($param !== null && !is_string($param)) {
            throw new InvalidArgumentException('Session cookie value must be a string');
        }
        return is_string($param) && $param !== '' ? $param : null;
    }

    /**
     * Resolve the cookie options for a request: an explicit option wins, the
     * policy is the application baseline, the request fills the gaps.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function deriveOptions(array $options, CookiePolicy $policy, ServerRequestInterface $request): array
    {
        if (!array_key_exists('secure', $options) && $policy->secure === null) {
            $options['secure'] = $request->getUri()->getScheme() === 'https';
        }
        if (!array_key_exists('domain', $options) && $policy->domain === null) {
            $options['domain'] = $request->getUri()->getHost();
        }
        return $options;
    }

    /**
     * Release the session storage. Persistence is separate from transport:
     * it must happen whatever response finally leaves, an outgoing failure
     * included, so the session data is never lost.
     */
    public static function release(CookieSessionInterface $session): void
    {
        $session->close();
    }

    /**
     * Emit the Set-Cookie header when the id changed — or expire the client
     * cookie when the session is destroyed. Applied to the final response.
     */
    public static function apply(
        CookieSessionInterface $session,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $name = $session->getName();
        $current = $request->getCookieParams()[$name] ?? null;

        if ($session->isDestroyed()) {
            return $current === null
                ? $response
                : $response->withAddedHeader('Set-Cookie', SetCookieHeader::build($name, '', $session->getCookieParams(), true));
        }

        $id = $session->getId();
        if ($id === null || $current === $id) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', SetCookieHeader::build($name, $id, $session->getCookieParams()));
    }
}

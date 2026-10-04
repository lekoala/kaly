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
 * the storage and emit the Set-Cookie header on commit. Providers delegate
 * here instead of knowing concrete session classes.
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
     * The domain is never inferred from the request host: an omitted Domain
     * keeps the cookie host-only instead of sharing it with subdomains.
     * Sharing stays an explicit choice through the policy or the options.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function deriveOptions(array $options, CookiePolicy $policy, ServerRequestInterface $request): array
    {
        if (!array_key_exists('secure', $options) && $policy->secure === null) {
            $options['secure'] = $request->getUri()->getScheme() === 'https';
        }
        return $options;
    }

    /**
     * Release the session storage, then emit the Set-Cookie header when the
     * id changed — or expire the client cookie when the session is destroyed.
     */
    public static function commit(
        CookieSessionInterface $session,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $session->close();

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

<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Http\Exception\InvalidMethodOverrideException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tunnels an HTML form submission to PUT, PATCH or DELETE.
 *
 * Only real POST requests are overridable, so GET, HEAD and OPTIONS stay
 * genuinely safe. The `_method` field is read from true HTML submissions
 * only (urlencoded or multipart bodies, never JSON); API clients use the
 * `X-HTTP-Method-Override` header, or better the real HTTP method. Two
 * different targets, or a target outside the whitelist, is a 400: ambiguity
 * is rejected rather than resolved by a silent precedence.
 *
 * Runs in the incoming band, before routing, so resolvers and downstream
 * middlewares (notably CSRF) see the effective method. Opt-in, disabled by
 * default: an API-only application never needs it.
 */
final readonly class MethodOverrideMiddleware implements MiddlewareInterface
{
    private const FIELD = '_method';

    private const HEADER = 'X-HTTP-Method-Override';

    /**
     * @var list<string>
     */
    private const ALLOWED = [
        'PUT',
        'PATCH',
        'DELETE',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $handler->handle($request);
        }

        $bodyMethod = $this->bodyMethod($request);
        $headerMethod = $this->headerMethod($request);

        if ($bodyMethod !== null && $headerMethod !== null && $bodyMethod !== $headerMethod) {
            throw new InvalidMethodOverrideException();
        }

        $method = $bodyMethod ?? $headerMethod;

        if ($method === null) {
            return $handler->handle($request);
        }

        if (!in_array($method, self::ALLOWED, true)) {
            throw new InvalidMethodOverrideException();
        }

        return $handler->handle($request->withMethod($method));
    }

    private function bodyMethod(ServerRequestInterface $request): ?string
    {
        $mediaType = MediaType::normalizeName(explode(';', $request->getHeaderLine('Content-Type'))[0]);

        if (!in_array($mediaType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
            return null;
        }

        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body[self::FIELD]) || !is_string($body[self::FIELD])) {
            return null;
        }

        $method = strtoupper(trim($body[self::FIELD]));

        return $method !== '' ? $method : null;
    }

    private function headerMethod(ServerRequestInterface $request): ?string
    {
        $method = strtoupper(trim($request->getHeaderLine(self::HEADER)));

        return $method !== '' ? $method : null;
    }
}

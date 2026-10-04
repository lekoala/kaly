<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Kaly\Core\HttpContext;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Exception\InvalidCsrfTokenException;
use Kaly\Http\Method;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the CSRF token of state-changing requests.
 *
 * Safe methods pass through untouched. Otherwise the token is read from the
 * parsed body field first, then from the header for SPA/fetch requests. A
 * missing or invalid token is a 403: the middleware never guesses from the
 * presence of a Bearer credential, the scope it is mounted on is the policy.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Csrf $csrf,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Method::isSafe($request->getMethod())) {
            return $handler->handle($request);
        }

        $token = $this->tokenFrom($request);

        if ($token === null || !$this->csrf->validate(HttpContext::from($request)->session(), $token)) {
            throw new InvalidCsrfTokenException();
        }

        return $handler->handle($request);
    }

    private function tokenFrom(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body[Csrf::FIELD]) && is_string($body[Csrf::FIELD]) && $body[Csrf::FIELD] !== '') {
            return $body[Csrf::FIELD];
        }

        $header = trim($request->getHeaderLine(Csrf::HEADER));

        return $header !== '' ? $header : null;
    }
}

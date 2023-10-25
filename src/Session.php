<?php

declare(strict_types=1);

namespace Kaly;

use Middlewares\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A session middleware using PhpSession as the base
 * @link https://github.com/middlewares/php-session/pull/10
 */
class Session implements MiddlewareInterface
{
    protected PhpSession $phpSession;
    protected string $rememberMeField = '_remember_me';
    protected int $rememberMeLifetime = 31536000;
    protected int $defaultLifetime = 0;
    protected string $tokenField = "_token";

    public function __construct(PhpSession $phpSession)
    {
        $this->phpSession = $phpSession;
    }

    protected function isRememberMe(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === "POST" && !empty($request->getParsedBody()[$this->rememberMeField]);
    }

    protected function calculateLifetime(ServerRequestInterface $request): int
    {
        if ($this->isRememberMe($request)) {
            return $this->rememberMeLifetime;
        }
        return $this->defaultLifetime;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Use safer defaults
        $options = [
            'secure' => $request->getUri()->getScheme() === 'https',
            'domain' => $request->getUri()->getHost(),
            'lifetime' => $this->calculateLifetime($request),
        ];
        $this->phpSession->options($options);

        // Generate a CSRF token
        $_SESSION[$this->tokenField] = bin2hex(random_bytes(32));

        return $this->phpSession->process($request, $handler);
    }
}

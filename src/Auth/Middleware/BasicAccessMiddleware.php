<?php

declare(strict_types=1);

namespace Kaly\Auth\Middleware;

use InvalidArgumentException;
use Kaly\Http\Authorization;
use Kaly\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A simple HTTP gate, typically for staging.
 *
 * Validates Basic credentials and continues, otherwise answers 401 with its
 * own challenge built from the realm. It never establishes an application
 * identity: an HTTP gate is not a login.
 */
final readonly class BasicAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $username,
        #[\SensitiveParameter]
        private string $password,
        private string $realm = 'Staging',
    ) {
        if ($username === '') {
            throw new InvalidArgumentException('Basic access username must not be empty');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Basic access password must not be empty');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $basic = Authorization::from($request)?->basic();

        if ($basic !== null && hash_equals($this->username, $basic['username']) && hash_equals($this->password, $basic['password'])) {
            return $handler->handle($request);
        }

        throw new UnauthorizedException($this->challenge());
    }

    private function challenge(): string
    {
        return sprintf('Basic realm="%s"', str_replace(['\\', '"'], ['\\\\', '\\"'], $this->realm));
    }
}

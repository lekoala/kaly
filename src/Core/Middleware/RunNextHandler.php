<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Kaly\Core\HttpContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal A handler that runs the next middleware
 */
final class RunNextHandler implements RequestHandlerInterface
{
    public function __construct(
        private Runner $runner,
        private int $nextIndex,
        private ?HttpContext $ctx = null,
    ) {
        // promoted
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runner->processNext($request, $this->nextIndex, $this->ctx);
    }
}

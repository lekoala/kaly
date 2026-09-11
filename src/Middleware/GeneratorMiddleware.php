<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Generator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An intuitive base class for creating generator-based middleware.
 * It hides the complex `yield` mechanic and provides simple before/after hooks.
 */
abstract class GeneratorMiddleware implements GeneratorMiddlewareInterface
{
    /**
     * Logic to execute on the request BEFORE inner layers are run.
     *
     * @return ServerRequestInterface The (potentially modified) request.
     */
    public function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Default is to do nothing.
        return $request;
    }

    /**
     * Logic to execute on the response AFTER inner layers are run.
     *
     * @param ServerRequestInterface $request The original request.
     * @param ResponseInterface $response The response from the inner layers.
     * @return ResponseInterface The (potentially modified) response.
     */
    public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Default is to do nothing.
        return $response;
    }

    /**
     * This final method implements the "magic".
     * Developers using this base class should NOT override this.
     * It orchestrates the before -> yield -> after flow.
     *
     * @return Generator<int, ServerRequestInterface, ResponseInterface, ResponseInterface>
     * @final
     */
    final public function process(ServerRequestInterface $request): Generator
    {
        // Run the "before" logic
        $modifiedRequest = $this->before($request);

        // Send request to the runner, get response in return
        $responseFromInside = yield $modifiedRequest;

        // Run the "after" logic and return the final result
        return $this->after($request, $responseFromInside);
    }
}

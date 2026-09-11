<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\ResponseEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A basic app that should be created from the entry file
 *
 * It is a thin facade over the Application bootstrap and the request Kernel.
 */
class App extends Application implements RequestHandlerInterface
{
    /**
     * Handle a request and returns its response
     * This may be called back by middlewares
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertBooted();

        return $this->getKernel()->handle($request);
    }

    /**
     * This utility method can be used for index scripts
     * It will send the response
     */
    public function run(ServerRequestInterface $request): void
    {
        if (!$this->getBooted()) {
            $this->boot();
        }

        $response = $this->handle($request);

        $emitter = new ResponseEmitter();
        $emitter->emit($response);
    }
}

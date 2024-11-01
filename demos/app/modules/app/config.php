<?php

/** @var Kaly\Core\Module $this */

use Kaly\Core\MiddlewareRunner;
use Kaly\View\Engine;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;

// $def->callback(MiddlewareRunner::class, function (MiddlewareRunner $mr) {
//     $errorMiddleware = new class implements MiddlewareInterface {
//         public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
//         {
//             throw new Exception("I'm an error from a module");
//         }
//     };
//     $mr->push($errorMiddleware);
// });

// Main module
$this->definitions()
    ->callback(Engine::class, function (Engine $engine) {
        $engine->setDir(__DIR__ . '/templates');
    })
    ->lock();

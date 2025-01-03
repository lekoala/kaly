<?php
// phpcs:ignoreFile

use Kaly\Di\Definitions;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Kaly\Middleware\FileServer;
use Kaly\Middleware\FaviconServer;
use Kaly\Middleware\PreventFileAccess;
use Kaly\Http\ResponseEmitter;

ini_set('display_errors', 'on');
error_reporting(-1);

require "vendor/autoload.php";

$demoMiddleware = new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // echo time() . "<br/>";
        $request = $request->withAttribute('x-demo', time());
        return $handler->handle($request);
    }
};
$errorMiddleware = new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new Exception("I'm exceptional");
    }
};

ErrorHandler::handle(function () use ($demoMiddleware, $errorMiddleware) {
    $app = new App(dirname(__DIR__));
    $app->boot();
    $app->getMiddlewareRunner()
        ->push(FaviconServer::class, null, true)
        ->push(PreventFileAccess::class, null, true)
        ->push($demoMiddleware, null, true);

    // Uncomment this to test for errors during middleware processing
    // $app->getMiddlewareRunner()->push($errorMiddleware, null, true);

    $app->getMiddlewareRunner()->push(new FileServer());

    $app->addCallback(App::CB_AFTER_DEFINITIONS, function (Definitions &$definitions) {
        $definitions->set("test", Definitions::class);
        // d($definitions);
    });
    $app->addCallback(App::CB_AFTER_REQUEST, function () use ($app) {
        // d($app);
    });

    $app->run();
    // $response = $app->handle();

    // handling a second time should work!
    // $response = $app->handle();

    // $emitter = new ResponseEmitter();
    // $emitter->emit($response);
});

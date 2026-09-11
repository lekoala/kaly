<?php
// phpcs:ignoreFile

use Kaly\Di\Definitions;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Middleware\Builtin\PreventFileAccess;

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
    $app->middleware()
        ->incoming(PreventFileAccess::class)
        ->incoming($demoMiddleware);

    // Uncomment this to test for errors during middleware processing
    // $app->middleware()->incoming($errorMiddleware);

    $app->middleware()->incoming(new FileServer(), priority: 100);

    $app->addCallback(App::CB_AFTER_DEFINITIONS, function (Definitions &$definitions) {
        $definitions->set("test", Definitions::class);
        // d($definitions);
    });
    $app->addCallback(App::CB_AFTER_REQUEST, function () use ($app) {
        // d($app);
    });

    // The core is implementation agnostic: the entry point builds the request.
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        $psr17Factory, // ServerRequestFactory
        $psr17Factory, // UriFactory
        $psr17Factory, // UploadedFileFactory
        $psr17Factory, // StreamFactory
    );
    $request = $creator->fromGlobals();

    $app->run($request);
});

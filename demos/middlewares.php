<?php

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Nyholm\Psr7\Response;
use Kaly\Middleware\PredefinedResponseHandler;
use Psr\Http\Message\ResponseInterface;
use Kaly\Middleware\GeneratorMiddleware;
use Kaly\Middleware\GeneratorMiddlewareInterface;
use Kaly\Middleware\MiddlewareRunner;
use Nyholm\Psr7\ServerRequest;

require "../vendor/autoload.php";

// 1. A native GeneratorMiddleware
class AddTimestampMiddleware implements GeneratorMiddlewareInterface
{
    public function process(ServerRequestInterface $request): \Generator
    {
        echo "[AddTimestampMiddleware] Processing request.<br/>";
        $request = $request->withAddedHeader('X-Received-At', (string)time());

        $response = yield $request; // Pass through on request
        echo "[AddTimestampMiddleware] Adding timestamp header.<br>";

        $response = $response->withHeader('X-Received-At', $request->getHeaderLine('X-Received-At'));
        $response = $response->withHeader('X-Processed-At', (string)time()); // Should be exactly +1 since we sleep for 1 second

        return $response;
    }
}

// 2. A standard PSR-15 Middleware
// The process method is called twice when handle() is used because there is no way to know
// if handle will be called or if a response will be returned
class StandardAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        echo "[StandardAuthMiddleware] Checking authentication.<br>";
        if ($request->getHeaderLine('X-Api-Key') !== '12345') {
            echo "[StandardAuthMiddleware] <b>Auth Failed!</b> Short-circuiting.<br>";
            return new Response(401, [], 'Unauthorized');
        }

        $response = $handler->handle($request); // Continue to next layer

        echo "[StandardAuthMiddleware] Auth check complete on response.<br>";
        return $response->withHeader('X-Auth-Status', 'OK');
    }
}

// 3. A demo logger that never returns a response. We can flag it as "optimistic"
class SimplePsr15Logger implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        echo "[SimplePsr15Logger] Before handle log.<br>";

        $response = $handler->handle($request); // Continue to next layer

        echo "[SimplePsr15Logger] After handle log.<br>";
        return $response;
    }
}

// --- The Final Application Handler, it's just a middleware ---
class AppHandler implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        echo "[AppHandler] sleep 1 second.<br/>";
        sleep(1);
        $name = $request->getQueryParams()['name'] ?? 'User';
        // Return a response instead of calling $handler->handle($request);
        return new Response(200, [], "<h1>Welcome, {$name}</h1>");
    }
}
// --- Putting it all together (The new, clean way) ---

echo "<h2>Test Case: Successful Run</h2>";

$runner = (new MiddlewareRunner(new AppHandler()))
    ->push(new AddTimestampMiddleware())
    ->push(new SimplePsr15Logger())
    ->push(new StandardAuthMiddleware());

$request = new ServerRequest('GET', '/welcome?name=Final', ['X-Api-Key' => '12345']);
$response = $runner->handle($request);

echo "Status: " . $response->getStatusCode() . "<br>";
echo "Headers: " . print_r($response->getHeaders(), true) . "<br>";
echo "Body: " . $response->getBody();

echo "<h2>Test Case: Failed Run</h2>";

$request = new ServerRequest('GET', '/welcome?name=Final', ['X-Api-Key' => 'invalid']);
$response = $runner->handle($request);

echo "Status: " . $response->getStatusCode() . "<br>";
echo "Headers: " . print_r($response->getHeaders(), true) . "<br>";
echo "Body: " . $response->getBody();


// Check the stack when using our GeneratorMiddlewares

$middleware1 = new class implements GeneratorMiddlewareInterface {
    public function process(ServerRequestInterface $request): Generator
    {
        echo "processing middleware1<br/>";
        // Handle the incoming request

        // return new Response('200', [], 'test short-circuit');

        // Update the request before next middleware
        echo "update request in middleware1<br/>";
        $request = $request->withAddedHeader('x-from1', 'true');

        // Invoke the next middleware and get response
        $response =  yield $request;

        // Handle the outgoing response

        // Update the response before next middleware
        echo "update response in middleware1<br/>";
        $response = $response->withAddedHeader('x-from1', 'true');

        return $response;
    }
};

$middleware2 = new class implements GeneratorMiddlewareInterface {
    public function process(ServerRequestInterface $request): Generator
    {
        echo "processing middleware2<br/>";

        // Handle the incoming request
        // ...

        // return new Response('200', [], 'test short-circuit, will still run middleware1 fully');

        // Invoke the next middleware and get response
        $response =  yield $request;

        // Handle the outgoing response

        // This is never triggered if an exception is throw in ExceptionHandler
        // It can read updated request and response after middleware1
        echo "reading request in middleware2<br/>";
        if ($request->hasHeader('x-from1')) {
            $response = $response->withAddedHeader('x-req-from1', 'true');
        }

        echo "reading response in middleware2<br/>";
        if ($response->hasHeader('x-from1')) {
            $response = $response->withAddedHeader('x-res-from1', 'true');
        }

        return $response;
    }
};

class ExceptionHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        echo '[ExceptionHandler] executing<br/>';
        throw new Exception("No middlewares in the stack trace");
        return new Response(200, [], "<h1>Will never show</h1>");
    }
}
class RegularHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        echo '[RegularHandler] executing<br/>';
        return new Response(200, [], "<h1>Will show</h1>");
    }
}

echo '<h2>Testing generator middlewares</h2>';

$psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

$creator = new \Nyholm\Psr7Server\ServerRequestCreator(
    $psr17Factory, // ServerRequestFactory
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory  // StreamFactory
);

$serverRequest = $creator->fromGlobals();

$responseBody = $psr17Factory->createStream('Hello world');
$response = $psr17Factory->createResponse(200)->withBody($responseBody);

$regularHandler = new RegularHandler();
$stack = new MiddlewareRunner($regularHandler);
$stack->push($middleware1);
$stack->push($middleware2);

echo '<pre>';
$stackResponse = $stack->handle($serverRequest);

echo '<br/>';
echo "Status: " . $stackResponse->getStatusCode() . "<br>";
echo "Headers: " . print_r($stackResponse->getHeaders(), true) . "<br>";
echo "Body: " . $stackResponse->getBody();

echo '<hr/>';

$handler = new ExceptionHandler();
$stack = new MiddlewareRunner($handler);
$stack->push($middleware1);
$stack->push($middleware2);

echo '<pre>';
$stackResponse = $stack->handle($serverRequest);

dd($stackResponse, $stackResponse->getBody()->getContents());

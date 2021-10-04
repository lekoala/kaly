# App

> Application kernel

## Usage

A kaly app is a simple class that wraps execution of your application.

It takes one parameter : the base path.

## Index file

Here is a sample file to get you started

```php
<?php

require '../vendor/autoload.php';

error_reporting(-1);

$app = new Kaly\App(dirname(__DIR__));
$app->run();
```

## Using Road Runner

You can also use RoadRunner to handle requests. Since the boot process
happens only once, you get a really minimal overhead to handle requests.

```php
<?php

use Spiral\RoadRunner;
use Nyholm\Psr7;

require "vendor/autoload.php";

$worker = RoadRunner\Worker::create();
$psrFactory = new Psr7\Factory\Psr17Factory();

$worker = new RoadRunner\Http\PSR7Worker($worker, $psrFactory, $psrFactory, $psrFactory);

$app = new Kaly\App(dirname(__DIR__));
$app->boot();

while ($req = $worker->waitRequest()) {
    try {
        $response = $app->handle($req);
        $worker->respond($response);
    } catch (\Throwable $e) {
        $worker->getWorker()->error((string)$e);
    }
}
```

## Bootstrap

The `run` method will do a couple of things. It will:
- create a request object if none is passed
- load env variables
- load your modules
- configure the di container
- route the request and handle exceptions if necessary
- controller result is then converted to a proper response
  1. use provided response if available
  2. or convert body to json response if header is set or using _json get variable
  3. or convert body to html response

## Using middlewares

It is possible to use middlewares like this. Middleware support is really simple:
they all run on each request. They get instantiated by the DI container.

```php
$app = new Kaly\App(dirname(__DIR__));
$app->boot();
// add a debug only middleware
$app->addMiddleware(Whoops::class, true);
// add client ip for all requests
$app->addMiddleware(ClientIp::class);
$app->run();

```

## Env variables

Any env variables should be defined in the application server.

Otherwise, you can also have an `.env` in the root of your folder.
`.env` file are simply passed to `parse_ini_file` method.
You can avoid the filesystem call (checking if .env exists) by setting
a `ignore_dot_env` environment variable.

The only processing we do is converting "true" and "false" strings to actual booleans.

Then we check our applications environment variables:
- debug : toggle debug mode for the app. Useful in development.

## Modules

In a kaly app, all folders in the modules dir with a `config.php` are considered to be modules.
Config files are executed during the bootstrap process and can return definitions
to be injected in the DI container.

> You still need to autoload your modules yourself in composer.json

> Convention: modules folder should match their namespace. We use uppercased folders for
consistency.

> The default namespace for the built-in router is `App` and it makes sense to have
a module matching this.

> Modules are not loaded in any particular order by default.

## The DI container

All definitions provided by the config modules are then loaded up in the Di container.

Some convention based keys are also added:
- The application itself is added to the container (and the base App class as well if you extend it)
- The request is also stored (under its class name and the "request" alias)
- The default router is registered if not provided

## Routing

The routing is done by a class implementing the `RouterInterface`.

See our `ClassRouter` docs for more information.

The router is also responsible to call your controllers in any way you see fit.

Result of the controller call should be a `Response` object or a string/Stringable object.
An array is also valid for json responses.

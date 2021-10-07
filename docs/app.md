# App

> Application kernel

## Usage

A kaly app is a simple class that wraps execution of your application.

It takes one parameter : the base path.

Note: the app kernel will look by default for a .env file (see "env variables" below).

The env variables are following the same conventions as Laravel or Symfony.

```
APP_DEBUG=true
```

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
- it will boot the app if not already done
- it will handle the request (from globals if none passed)
- and it will output the response

## Using middlewares

It is possible to use middlewares like this. Middleware support is really simple:
they all run on each request. They get instantiated by the DI container.

```php
$app = new Kaly\App(dirname(__DIR__));
// add a debug only middleware as error handler
$app->addErrorHandler(Whoops::class, true);
// add client ip for all requests
$app->addMiddleware(ClientIp::class);
$app->run();

```

## Env variables

Any env variables should be defined in the application server.

Otherwise, you can also have an `.env` in the root of your folder.
`.env` file are simply passed to `parse_ini_file` method.
You can avoid the filesystem call (checking if .env exists) by setting
a `IGNORE_DOT_ENV` environment variable.

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

Look into the App::configureDi method to see what's being done here.

Please note the the Di container is only instantiated ONCE, when the application is booted.
This means that subsequent requests on the same app instance will use the same Di container.

As a result, we do not have direct access to the request object, as it would be cached.
Instead, it is recommended to use our State object that get updated with the latest request instance
each time.

## Routing

The routing is done by a class implementing the `RouterInterface`.

See our `ClassRouter` docs for more information.

The router is also responsible to call your controllers in any way you see fit.

Result of the controller call should be a `Response` object or a string/Stringable object.
An array is also valid for json responses.

## Json responses

Any request accept json responses or using the ?_json flag can get a json response.

This is only triggered if the route parameters have a json flag set to true.

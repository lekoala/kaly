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

In a kaly app, all folders in the base dir with a `config.php` are considered to be modules.
Config files are executed during the bootstrap process and can return definitions
to be injected in the DI container.

> Convention: modules folder should match their namespace. We use uppercased folders for
consistency. It also have a nice side effect to easily spot modules vs regular folders.

> The default module is `App`.

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

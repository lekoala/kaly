# DI

> A strict PSR-11 container

## Introduction

There are many DI solutions out there, my favorites being:

- https://php-di.org/
- https://capsulephp.com/
- https://symfony.com/doc/current/components/dependency_injection.html
- https://github.com/yiisoft/di

While they are all very great, there are a few things I don't like about them:

- Array or verbose configuration
- Doing "too much" or creeping out of scope

In order to fix these issues, this is how the DI component work:

- Start with a definitions object that allows defining our services in a simple, unambiguous manner
- Pass theses definitions to an Injector, that can create classes and call methods
- Pass this injector to a Container that can retrieve services and caches them

## Usage

The DI container only public methods are `has` and `get` methods. The basic usage is like this:

```php
$di = new Di();

// Get an existing class
$di->get(User::class);
// Check if the class is defined in the container
$di->has(User::class);
```

Any dependency from the User class will be resolved automatically if available in the container.

## Create definitions

## Strict definitions

The Di container is very permissive by default, because it will allow you to create
any existing class.

But sometimes, you need actual definitions in order to be able to create useful classes. This
is where strict definitions come into play. You can pass an array of definitions ids that will
need actual definitions instead of simple class_exists calls.

## Exceptions

The DI component can throw 3 exceptions, two from the PSR:

- ReferenceNotFoundException
- ContainerException

And one custom:

- CircularReferenceException
